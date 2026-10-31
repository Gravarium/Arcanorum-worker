<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Platform\Hosting\HostingCachePoolProvider;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\ContentCategoryRepository;
use App\Repository\VideoRepository;
use App\Service\SiteSettingsProvider;
use App\Service\VideoProviderImportException;
use App\Service\VideoProviderImportService;
use App\Service\VideoSourceUrlLock;
use App\Service\VideoThumbnailImportBudget;
use App\Service\VideoThumbnailImportService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_ADMIN')]
final class VideoProviderImportController extends AbstractController
{
    #[Route('/admin/videos/metadata', name: 'admin_video_metadata', methods: ['POST'])]
    public function metadata(
        Request $request,
        VideoProviderImportService $providerImport,
        SiteSettingsProvider $siteSettings,
        HostingCachePoolProvider $importRateLimitCache,
        RateLimitKeyFactory $rateLimitKeys,
        TranslatorInterface $translator,
        #[Autowire(service: 'limiter.video_provider_import')] RateLimiterFactory $importRateLimiter,
    ): JsonResponse {
        $submitted = $request->request->all();
        $token = $submitted['_token'] ?? null;
        if (!is_string($token) || '' === $token || !$this->isCsrfTokenValid('admin_video_metadata', $token)) {
            return self::metadataResponse(['message' => $translator->trans('Die Metadatenanfrage ist ungültig.')], Response::HTTP_FORBIDDEN);
        }

        if ([] !== array_diff(array_keys($submitted), ['_token', 'source_url'])
            || !is_string($submitted['source_url'] ?? null)
            || '' === trim($submitted['source_url'])
            || strlen($submitted['source_url']) > 2048
        ) {
            return self::metadataResponse(['message' => $translator->trans('Bitte gib eine gültige Provider-Video-URL ein.')], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$importRateLimitCache->usesRedisPool()) {
            return self::metadataResponse(
                ['message' => $translator->trans('Der gemeinsame Importschutz ist nicht verfügbar. Der Anbieter wurde nicht angefragt.')],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '60'],
            );
        }

        $admin = $this->getUser();
        if (null === $admin) {
            throw $this->createAccessDeniedException();
        }
        $limiterKey = $rateLimitKeys->forIdentity($admin->getUserIdentifier(), 'video-provider-import');
        $limit = $importRateLimiter->create($limiterKey)->consume();
        if (!$limit->isAccepted()) {
            return self::metadataResponse(
                ['message' => $translator->trans('Das Importlimit wurde erreicht. Bitte warte kurz und versuche es erneut.')],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())],
            );
        }

        try {
            $metadata = $providerImport->metadataForSourceUrl(
                trim($submitted['source_url']),
                $siteSettings->current()->getProviderThumbnailResolution(),
            );
        } catch (VideoProviderImportException) {
            return self::metadataResponse(
                ['message' => $translator->trans('Der Videoanbieter konnte keine sicheren Metadaten bereitstellen. Bitte prüfe die Providerkonfiguration und versuche es erneut.')],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        if (null === $metadata) {
            return self::metadataResponse(
                ['message' => $translator->trans('Für diese URL sind keine unterstützten und einbettbaren Provider-Metadaten verfügbar.')],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return self::metadataResponse($metadata, Response::HTTP_OK);
    }

    #[Route('/admin/videos/import/provider', name: 'admin_video_provider_import', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        VideoProviderImportService $providerImport,
        VideoThumbnailImportService $thumbnailImports,
        SiteSettingsProvider $siteSettings,
        VideoSourceUrlLock $sourceLock,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
        EntityManagerInterface $entityManager,
        HostingCachePoolProvider $importRateLimitCache,
        RateLimitKeyFactory $rateLimitKeys,
        LoggerInterface $logger,
        TranslatorInterface $translator,
        #[Autowire(service: 'limiter.video_provider_import')] RateLimiterFactory $importRateLimiter,
    ): Response {
        $values = [
            'provider' => 'youtube',
            'mode' => 'search',
            'query' => '',
            'channel_id' => '',
            'playlist_id' => '',
            'category' => '',
        ];
        $errors = [];
        $status = Response::HTTP_OK;
        $headers = [];

        if ($request->isMethod('POST')) {
            $submitted = $request->request->all();
            $token = $submitted['_token'] ?? null;
            if (!is_string($token) || '' === $token || !$this->isCsrfTokenValid('admin_video_provider_import', $token)) {
                throw $this->createAccessDeniedException();
            }

            $allowedFields = ['_token', 'provider', 'mode', 'query', 'channel_id', 'playlist_id', 'category'];
            $hasInvalidFields = [] !== array_diff(array_keys($submitted), $allowedFields);
            foreach (['provider', 'mode', 'query', 'channel_id', 'playlist_id', 'category'] as $field) {
                if (array_key_exists($field, $submitted) && !is_string($submitted[$field])) {
                    $hasInvalidFields = true;
                }
            }

            $values = [
                'provider' => self::input($submitted, 'provider'),
                'mode' => self::input($submitted, 'mode'),
                'query' => trim(self::input($submitted, 'query')),
                'channel_id' => trim(self::input($submitted, 'channel_id')),
                'playlist_id' => trim(self::input($submitted, 'playlist_id')),
                'category' => trim(self::input($submitted, 'category')),
            ];

            if ($hasInvalidFields) {
                $errors[] = 'Die Anfrage enthält ungültige oder unbekannte Importfelder.';
            } elseif (!VideoProviderImportService::isSupportedProvider($values['provider'])) {
                $errors[] = 'Bitte wähle einen unterstützten Videoanbieter.';
            } elseif (!in_array($values['mode'], ['search', 'channel', 'playlist', 'favorites'], true)) {
                $errors[] = 'Bitte wähle eine gültige Importart.';
            } elseif ('search' === $values['mode'] && !VideoProviderImportService::isValidSearchTerm($values['query'])) {
                $errors[] = 'Der Suchbegriff muss 2 bis 100 gültige UTF-8-Zeichen enthalten.';
            } elseif ('channel' === $values['mode'] && !VideoProviderImportService::isValidChannelId($values['provider'], $values['channel_id'])) {
                $errors[] = sprintf('Gib eine gültige %s ein.', VideoProviderImportService::channelLabel($values['provider']));
            } elseif ('playlist' === $values['mode'] && !VideoProviderImportService::isValidPlaylistId($values['provider'], $values['playlist_id'])) {
                $errors[] = sprintf('Gib eine gültige %s ein.', VideoProviderImportService::playlistLabel($values['provider']));
            } elseif ('favorites' === $values['mode']
                && 'dailymotion' === $values['provider']
                && !VideoProviderImportService::isValidChannelId($values['provider'], $values['channel_id'])
            ) {
                $errors[] = sprintf('Gib eine gültige %s für die Favoritenliste ein.', VideoProviderImportService::channelLabel($values['provider']));
            }

            if (mb_strlen($values['category'], 'UTF-8') > 100
                || self::containsControlCharacters($values['category'])
                || ('' !== $values['category']
                    && null === $categories->findForTypeAndName(ContentCategoryType::Video, $values['category']))
            ) {
                $errors[] = 'Bitte wähle eine vorhandene Videokategorie.';
            }

            if ([] !== $errors) {
                $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            } elseif (!$providerImport->isConfiguredForMode($values['provider'], $values['mode'])) {
                $configurationName = match ($values['provider']) {
                    'youtube' => 'favorites' === $values['mode']
                        ? 'YOUTUBE_OAUTH_CLIENT_ID, YOUTUBE_OAUTH_CLIENT_SECRET und YOUTUBE_OAUTH_REFRESH_TOKEN'
                        : 'YOUTUBE_DATA_API_KEY',
                    'vimeo' => 'VIMEO_ACCESS_TOKEN mit Lesezugriff auf die Likes des verbundenen Kontos',
                    'dailymotion' => 'DAILYMOTION_PLAYER_ID; für Kanal- und Playlistimporte außerdem DAILYMOTION_CLIENT_ID und DAILYMOTION_CLIENT_SECRET',
                    default => 'eine gültige Serverkonfiguration',
                };
                $errors[] = sprintf('Der Import ist nicht verfügbar. Der Server benötigt eine gültige Konfiguration für %s.', $configurationName);
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
            } elseif (!$importRateLimitCache->usesRedisPool()) {
                $errors[] = 'Der gemeinsame Importschutz ist nicht verfügbar. Der Anbieter wurde nicht angefragt.';
                $status = Response::HTTP_SERVICE_UNAVAILABLE;
                $headers['Retry-After'] = '60';
                $items = [];
            } else {
                $admin = $this->getUser();
                if (null === $admin) {
                    throw $this->createAccessDeniedException();
                }

                $limiterKey = $rateLimitKeys->forIdentity($admin->getUserIdentifier(), 'video-provider-import');
                $limit = $importRateLimiter->create($limiterKey)->consume();
                if (!$limit->isAccepted()) {
                    $errors[] = 'Das Importlimit wurde erreicht. Bitte warte kurz und versuche es erneut.';
                    $status = Response::HTTP_TOO_MANY_REQUESTS;
                    $headers['Retry-After'] = (string) max(1, $limit->getRetryAfter()->getTimestamp() - time());
                    $items = [];
                } else {
                    try {
                        $items = match ($values['mode']) {
                            'playlist' => $providerImport->playlist(
                                $values['provider'],
                                $values['playlist_id'],
                                $siteSettings->current()->getProviderThumbnailResolution(),
                            ),
                            'favorites' => $providerImport->favorites(
                                $values['provider'],
                                'dailymotion' === $values['provider'] ? $values['channel_id'] : null,
                                $siteSettings->current()->getProviderThumbnailResolution(),
                            ),
                            default => $providerImport->search(
                                $values['provider'],
                                'search' === $values['mode'] ? $values['query'] : '',
                                'channel' === $values['mode'] ? $values['channel_id'] : null,
                                $siteSettings->current()->getProviderThumbnailResolution(),
                            ),
                        };
                    } catch (VideoProviderImportException) {
                        $errors[] = sprintf('%s konnte die Anfrage nicht sicher beantworten. Bitte prüfe die Serverkonfiguration und versuche es später erneut.', VideoProviderImportService::providerLabel($values['provider']));
                        $status = Response::HTTP_BAD_GATEWAY;
                        $items = [];
                    }
                }

                if ([] === $errors && [] === $items) {
                    $errors[] = sprintf('%s hat keine passenden, einbettbaren Videos zurückgegeben.', VideoProviderImportService::providerLabel($values['provider']));
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                }

                if ([] === $errors) {
                    $sourceUrls = array_column($items, 'source_url');
                    $thumbnailBySourceUrl = [];
                    $thumbnailObjectIds = [];
                    $thumbnailImportFailures = 0;
                    $thumbnailBudget = new VideoThumbnailImportBudget();
                    $attachedThumbnailObjectIds = [];

                    try {
                        foreach ($items as $item) {
                            if (!isset($item['thumbnail_url']) || null !== $videos->findBySourceUrl($item['source_url'])) {
                                continue;
                            }
                            if ($thumbnailBudget->isExhausted()) {
                                ++$thumbnailImportFailures;

                                continue;
                            }

                            try {
                                $thumbnail = $thumbnailImports->storeFromProviderUrl(
                                    $values['provider'],
                                    $item['thumbnail_url'],
                                    $thumbnailBudget,
                                );
                                $thumbnailBySourceUrl[$item['source_url']] = $thumbnail;
                                $thumbnailObjectIds[] = $thumbnail['object_id'];
                            } catch (\InvalidArgumentException) {
                                ++$thumbnailImportFailures;
                            }
                        }

                        /** @var array{created: int<0, max>, skipped: int<0, max>} $importCounts */
                        $importCounts = $entityManager->getConnection()->transactional(function (Connection $connection) use (
                            $sourceUrls,
                            $items,
                            $sourceLock,
                            $videos,
                            $entityManager,
                            $values,
                            $thumbnailBySourceUrl,
                            &$attachedThumbnailObjectIds,
                        ): array {
                            $sourceLock->lock($connection, $sourceUrls);

                            $createdCount = 0;
                            $skippedCount = 0;
                            foreach ($items as $item) {
                                if (null !== $videos->findBySourceUrl($item['source_url'])) {
                                    ++$skippedCount;

                                    continue;
                                }

                                $video = new Video(
                                    $item['title'],
                                    $item['description'],
                                    '' === $values['category'] ? null : $values['category'],
                                    VideoSourceType::Embed,
                                    $item['source_url'],
                                    PublishingStatus::Draft,
                                    false,
                                    false,
                                );
                                if (isset($thumbnailBySourceUrl[$item['source_url']])) {
                                    $thumbnail = $thumbnailBySourceUrl[$item['source_url']];
                                    $video->attachThumbnail($thumbnail['object_id'], $thumbnail['mime_type'], $thumbnail['size']);
                                    $attachedThumbnailObjectIds[] = $thumbnail['object_id'];
                                }
                                $entityManager->persist($video);
                                ++$createdCount;
                            }

                            $entityManager->flush();

                            return ['created' => $createdCount, 'skipped' => $skippedCount];
                        });
                    } catch (\Throwable $exception) {
                        self::deleteThumbnails($thumbnailImports, $thumbnailObjectIds, $logger);
                        throw $exception;
                    }

                    self::deleteThumbnails(
                        $thumbnailImports,
                        array_values(array_diff($thumbnailObjectIds, $attachedThumbnailObjectIds)),
                        $logger,
                    );

                    $successMessage = sprintf(
                        '%d %s-Videos wurden als Entwürfe angelegt; %d vorhandene Einträge wurden übersprungen.',
                        $importCounts['created'],
                        VideoProviderImportService::providerLabel($values['provider']),
                        $importCounts['skipped'],
                    );
                    if ($thumbnailImportFailures > 0) {
                        $successMessage .= ' '.$translator->trans(
                            'Für %count% Videos konnte kein Vorschaubild übernommen werden.',
                            ['%count%' => $thumbnailImportFailures],
                        );
                    }
                    $this->addFlash(
                        'success',
                        $successMessage,
                    );

                    return $this->redirectToRoute('admin_video_index');
                }
            }
        }

        $providerOptions = [];
        foreach (VideoProviderImportService::providers() as $key => $label) {
            $providerOptions[$key] = [
                'label' => $label,
                'configured' => $providerImport->isConfiguredForMode($key, $values['mode']),
            ];
        }

        return $this->render('admin/videos/provider_import.html.twig', [
            'categories' => $categories->findForAdmin(ContentCategoryType::Video),
            'configured' => $providerImport->isConfiguredForMode($values['provider'], $values['mode']),
            'errors' => $errors,
            'providers' => $providerOptions,
            'values' => $values,
        ], new Response(status: $status, headers: $headers));
    }

    private static function containsControlCharacters(string $value): bool
    {
        return 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value);
    }

    /** @param array<string, mixed> $values */
    private static function input(array $values, string $key): string
    {
        $value = $values[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /** @param list<string> $objectIds */
    private static function deleteThumbnails(
        VideoThumbnailImportService $thumbnailImports,
        array $objectIds,
        LoggerInterface $logger,
    ): void {
        foreach (array_unique($objectIds) as $objectId) {
            try {
                $thumbnailImports->delete($objectId);
            } catch (\Throwable $exception) {
                $logger->error('Ein nicht verwendetes Provider-Vorschaubild konnte nicht bereinigt werden.', [
                    'failure_type' => $exception::class,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers
     */
    private static function metadataResponse(array $payload, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse($payload, $status, $headers + ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie']);
    }
}
