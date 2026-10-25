<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Platform\Hosting\HostingCachePoolProvider;
use App\Repository\ContentCategoryRepository;
use App\Repository\VideoRepository;
use App\Service\VideoProviderImportException;
use App\Service\VideoProviderImportService;
use App\Service\VideoSourceUrlLock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class VideoProviderImportController extends AbstractController
{
    #[Route('/admin/videos/import/provider', name: 'admin_video_provider_import', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        VideoProviderImportService $providerImport,
        VideoSourceUrlLock $sourceLock,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
        EntityManagerInterface $entityManager,
        HostingCachePoolProvider $importRateLimitCache,
        #[Autowire(service: 'limiter.video_provider_import')] RateLimiterFactory $importRateLimiter,
    ): Response {
        $values = [
            'provider' => 'youtube',
            'mode' => 'search',
            'query' => '',
            'channel_id' => '',
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

            $allowedFields = ['_token', 'provider', 'mode', 'query', 'channel_id', 'category'];
            $hasInvalidFields = [] !== array_diff(array_keys($submitted), $allowedFields);
            foreach (['provider', 'mode', 'query', 'channel_id', 'category'] as $field) {
                if (array_key_exists($field, $submitted) && !is_string($submitted[$field])) {
                    $hasInvalidFields = true;
                }
            }

            $values = [
                'provider' => self::input($submitted, 'provider'),
                'mode' => self::input($submitted, 'mode'),
                'query' => trim(self::input($submitted, 'query')),
                'channel_id' => trim(self::input($submitted, 'channel_id')),
                'category' => trim(self::input($submitted, 'category')),
            ];

            if ($hasInvalidFields) {
                $errors[] = 'Die Anfrage enthält ungültige oder unbekannte Importfelder.';
            } elseif (!VideoProviderImportService::isSupportedProvider($values['provider'])) {
                $errors[] = 'Bitte wähle einen unterstützten Videoanbieter.';
            } elseif (!in_array($values['mode'], ['search', 'channel'], true)) {
                $errors[] = 'Bitte wähle eine gültige Importart.';
            } elseif ('search' === $values['mode'] && !VideoProviderImportService::isValidSearchTerm($values['query'])) {
                $errors[] = 'Der Suchbegriff muss 2 bis 100 gültige UTF-8-Zeichen enthalten.';
            } elseif ('channel' === $values['mode'] && !VideoProviderImportService::isValidChannelId($values['provider'], $values['channel_id'])) {
                $errors[] = sprintf('Gib eine gültige %s ein.', VideoProviderImportService::channelLabel($values['provider']));
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
            } elseif (!$providerImport->isConfigured($values['provider'])) {
                $configurationName = 'youtube' === $values['provider'] ? 'YOUTUBE_DATA_API_KEY' : 'VIMEO_ACCESS_TOKEN';
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

                $limiterKey = hash('sha256', 'video-provider-import|'.$admin->getUserIdentifier());
                $limit = $importRateLimiter->create($limiterKey)->consume();
                if (!$limit->isAccepted()) {
                    $errors[] = 'Das Importlimit wurde erreicht. Bitte warte kurz und versuche es erneut.';
                    $status = Response::HTTP_TOO_MANY_REQUESTS;
                    $headers['Retry-After'] = (string) max(1, $limit->getRetryAfter()->getTimestamp() - time());
                    $items = [];
                } else {
                    try {
                        $items = $providerImport->search(
                            $values['provider'],
                            'search' === $values['mode'] ? $values['query'] : '',
                            'channel' === $values['mode'] ? $values['channel_id'] : null,
                        );
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

                    /** @var array{created: int<0, max>, skipped: int<0, max>} $importCounts */
                    $importCounts = $entityManager->getConnection()->transactional(function (Connection $connection) use (
                        $sourceUrls,
                        $items,
                        $sourceLock,
                        $videos,
                        $entityManager,
                        $values,
                    ): array {
                        $sourceLock->lock($connection, $sourceUrls);

                        $createdCount = 0;
                        $skippedCount = 0;
                        foreach ($items as $item) {
                            if (null !== $videos->findBySourceUrl($item['source_url'])) {
                                ++$skippedCount;

                                continue;
                            }

                            $entityManager->persist(new Video(
                                $item['title'],
                                $item['description'],
                                '' === $values['category'] ? null : $values['category'],
                                VideoSourceType::Embed,
                                $item['source_url'],
                                PublishingStatus::Draft,
                                false,
                                false,
                            ));
                            ++$createdCount;
                        }

                        $entityManager->flush();

                        return ['created' => $createdCount, 'skipped' => $skippedCount];
                    });

                    $this->addFlash(
                        'success',
                        sprintf(
                            '%d %s-Videos wurden als Entwürfe angelegt; %d vorhandene Einträge wurden übersprungen.',
                            $importCounts['created'],
                            VideoProviderImportService::providerLabel($values['provider']),
                            $importCounts['skipped'],
                        ),
                    );

                    return $this->redirectToRoute('admin_video_index');
                }
            }
        }

        $providerOptions = [];
        foreach (VideoProviderImportService::providers() as $key => $label) {
            $providerOptions[$key] = [
                'label' => $label,
                'configured' => $providerImport->isConfigured($key),
            ];
        }

        return $this->render('admin/videos/provider_import.html.twig', [
            'categories' => $categories->findForAdmin(ContentCategoryType::Video),
            'configured' => $providerImport->isConfigured($values['provider']),
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
}
