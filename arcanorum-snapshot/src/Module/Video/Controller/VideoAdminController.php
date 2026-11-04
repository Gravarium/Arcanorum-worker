<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Content\CustomFieldSet;
use App\Entity\CommentContentType;
use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ExternalVideoSourceStatus;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoQuality;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoEmbedCodeGenerator;
use App\Module\Video\Service\VideoEmbedOriginPolicy;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Platform\Hosting\HostingCachePoolProvider;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\ContentCategoryRepository;
use App\Repository\ContentCommentRepository;
use App\Repository\ContentTagRepository;
use App\Repository\VideoQualitySourceRepository;
use App\Repository\VideoRepository;
use App\Repository\VideoSubtitleTrackRepository;
use App\Service\ContentTagManager;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\SiteSettingsProvider;
use App\Service\VideoMediaUploadService;
use App\Service\VideoProviderImportException;
use App\Service\VideoProviderImportService;
use App\Service\VideoSlugLock;
use App\Service\VideoSourceUrlLock;
use App\Service\VideoSubtitleUploadService;
use App\Service\VideoThumbnailImportBudget;
use App\Service\VideoThumbnailImportService;
use App\Service\VideoThumbnailUploadService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/videos', name: 'admin_video_')]
#[IsGranted('ROLE_ADMIN')]
final class VideoAdminController extends AbstractController
{
    private const int ADMIN_PAGE_SIZE = 25;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
        ContentTagRepository $tags,
    ): Response {
        $search = mb_substr(trim(self::input($request->query, 'q')), 0, 180);
        $status = PublishingStatus::tryFrom(self::input($request->query, 'status'));
        $sourceType = VideoSourceType::tryFrom(self::input($request->query, 'source_type'));
        $category = mb_substr(trim(self::input($request->query, 'category')), 0, 100);
        $tag = mb_substr(trim(self::input($request->query, 'tag')), 0, 100);
        $sort = self::input($request->query, 'sort');
        if (!in_array($sort, ['created', 'title', 'status', 'views'], true)) {
            $sort = 'created';
        }
        $direction = 'asc' === strtolower(self::input($request->query, 'direction')) ? 'asc' : 'desc';
        $requestedPage = self::input($request->query, 'page');
        $page = ctype_digit($requestedPage) ? (int) $requestedPage : 1;
        $page = max(1, min(100000, $page));

        $categoryFilter = '' === $category ? null : $category;
        $tagFilter = '' === $tag ? null : $tag;
        $total = $videos->countForAdmin($search, $status, $categoryFilter, $tagFilter, $sourceType);
        $pageCount = max(1, (int) ceil($total / self::ADMIN_PAGE_SIZE));
        $page = min($page, $pageCount);
        $items = $videos->findForAdmin(
            search: $search,
            status: $status,
            limit: self::ADMIN_PAGE_SIZE,
            offset: ($page - 1) * self::ADMIN_PAGE_SIZE,
            category: $categoryFilter,
            tag: $tagFilter,
            sort: $sort,
            direction: $direction,
            sourceType: $sourceType,
        );

        return $this->render('admin/videos/index.html.twig', [
            'videos' => $items,
            'search' => $search,
            'status' => $status instanceof PublishingStatus ? $status->value : '',
            'source_type' => $sourceType->value ?? '',
            'source_types' => VideoSourceType::cases(),
            'category' => $category,
            'categories' => $categories->findForAdmin(ContentCategoryType::Video),
            'tag' => $tag,
            'tags' => $tags->findForAdmin(),
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
            'page_count' => $pageCount,
            'total' => $total,
        ]);
    }

    #[Route('/bulk', name: 'bulk', methods: ['POST'])]
    public function bulk(
        Request $request,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
        ContentCommentRepository $comments,
        VideoMediaUploadService $mediaUploads,
        VideoThumbnailUploadService $thumbnailUploads,
        VideoSubtitleTrackRepository $subtitleTracks,
        VideoSubtitleUploadService $subtitleUploads,
        EntityManagerInterface $entityManager,
        VideoProviderImportService $providerImport,
        HostingCachePoolProvider $rateLimitCache,
        RateLimitKeyFactory $rateLimitKeys,
        TranslatorInterface $translator,
        #[Autowire(service: 'limiter.video_provider_import')] RateLimiterFactory $providerRateLimiter,
    ): Response {
        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid('admin_video_bulk', $token)) {
            throw $this->createAccessDeniedException();
        }

        $payload = $request->request->all();
        $rawIds = $payload['ids'] ?? [];
        if (!is_array($rawIds)) {
            $this->addFlash('error', 'Bitte wähle gültige Einträge aus.');

            return $this->redirectToRoute('admin_video_index');
        }

        $ids = [];
        $seenIds = [];
        foreach ($rawIds as $rawId) {
            if (!is_string($rawId) || 1 !== preg_match('/\\A[a-f0-9]{32}\\z/D', $rawId)) {
                $this->addFlash('error', 'Die Auswahl enthält eine ungültige Kennung.');

                return $this->redirectToRoute('admin_video_index');
            }
            if (isset($seenIds[$rawId])) {
                continue;
            }

            $seenIds[$rawId] = true;
            $ids[] = $rawId;
            if (count($ids) > 50) {
                $this->addFlash('error', 'Pro Sammelaktion können höchstens 50 Einträge bearbeitet werden.');

                return $this->redirectToRoute('admin_video_index');
            }
        }

        if ([] === $ids) {
            $this->addFlash('error', 'Wähle mindestens einen Eintrag aus.');

            return $this->redirectToRoute('admin_video_index');
        }

        $action = self::input($request->request, 'action');
        if (!in_array($action, ['publish', 'draft', 'feature', 'unfeature', 'move', 'delete', 'check_source_status'], true)) {
            $this->addFlash('error', 'Bitte wähle eine gültige Sammelaktion aus.');

            return $this->redirectToRoute('admin_video_index');
        }
        if ('check_source_status' === $action && count($ids) > 5) {
            $this->addFlash('error', $translator->trans('Pro Quellenprüfung können höchstens fünf Videos ausgewählt werden.'));

            return $this->redirectToRoute('admin_video_index');
        }
        if ('delete' === $action && '1' !== self::input($request->request, 'confirm_delete')) {
            $this->addFlash('error', 'Bestätige das Löschen der ausgewählten Inhalte.');

            return $this->redirectToRoute('admin_video_index');
        }

        $targetCategoryName = null;
        if ('move' === $action) {
            if (!array_key_exists('category_id', $payload)) {
                $this->addFlash('error', 'Bitte wähle eine Zielkategorie aus.');

                return $this->redirectToRoute('admin_video_index');
            }
            $categoryId = self::input($request->request, 'category_id');
            if ('' !== $categoryId) {
                if (1 !== preg_match('/\A[a-f0-9]{32}\z/D', $categoryId)) {
                    $this->addFlash('error', 'Die Zielkategorie ist ungültig.');

                    return $this->redirectToRoute('admin_video_index');
                }
                $targetCategory = $categories->find($categoryId);
                if (!$targetCategory instanceof ContentCategory || ContentCategoryType::Video !== $targetCategory->getContentType()) {
                    $this->addFlash('error', 'Die Zielkategorie wurde nicht gefunden.');

                    return $this->redirectToRoute('admin_video_index');
                }
                $targetCategoryName = $targetCategory->getName();
            }
        }

        $items = $videos->findByIds($ids);
        if (count($items) !== count($ids)) {
            $this->addFlash('error', 'Mindestens ein ausgewählter Eintrag wurde nicht gefunden.');

            return $this->redirectToRoute('admin_video_index');
        }

        if ('check_source_status' === $action) {
            return $this->checkSourceStatus(
                $items,
                $videos,
                $entityManager,
                $providerImport,
                $rateLimitCache,
                $rateLimitKeys,
                $providerRateLimiter,
                $translator,
            );
        }

        $uploadedObjectIds = [];
        $thumbnailObjectIds = [];
        $subtitleObjectIds = [];
        $subtitleTracksToRemove = [];
        if ('delete' === $action) {
            foreach ($items as $item) {
                $objectId = $item->getUploadedObjectId();
                if (null !== $objectId) {
                    $uploadedObjectIds[] = $objectId;
                }
                $thumbnailObjectId = $item->getThumbnailObjectId();
                if (null !== $thumbnailObjectId) {
                    $thumbnailObjectIds[] = $thumbnailObjectId;
                }
                foreach ($subtitleTracks->findForVideo($item) as $subtitleTrack) {
                    $subtitleObjectIds[] = $subtitleTrack->getStorageObjectId();
                    $subtitleTracksToRemove[] = $subtitleTrack;
                }
            }
        }

        $entityManager->getConnection()->transactional(function () use ($items, $action, $comments, $entityManager, $targetCategoryName, $subtitleTracksToRemove): void {
            foreach ($subtitleTracksToRemove as $subtitleTrack) {
                $entityManager->remove($subtitleTrack);
            }

            foreach ($items as $item) {
                if ('delete' === $action) {
                    $comments->deleteForContent(CommentContentType::Video, $item->getId());
                    $entityManager->remove($item);

                    continue;
                }
                if ('publish' === $action) {
                    $item->setPublishingStatus(PublishingStatus::Published);
                } elseif ('draft' === $action) {
                    $item->setPublishingStatus(PublishingStatus::Draft);
                } elseif ('feature' === $action) {
                    $item->setFeatured(true);
                } elseif ('unfeature' === $action) {
                    $item->setFeatured(false);
                } elseif ('move' === $action) {
                    $item->setCategory($targetCategoryName);
                }
            }

            $entityManager->flush();
        });

        $mediaCleanupFailed = false;
        foreach ($uploadedObjectIds as $objectId) {
            try {
                $mediaUploads->delete($objectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $mediaCleanupFailed = true;
            }
        }
        foreach ($thumbnailObjectIds as $objectId) {
            try {
                $thumbnailUploads->delete($objectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $mediaCleanupFailed = true;
            }
        }
        foreach ($subtitleObjectIds as $objectId) {
            try {
                $subtitleUploads->delete($objectId);
            } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                $mediaCleanupFailed = true;
            }
        }
        if ($mediaCleanupFailed) {
            $this->addFlash('warning', 'Mindestens eine private Mediendatei konnte nicht bereinigt werden.');
        }

        $actionLabel = match ($action) {
            'publish' => 'veröffentlicht',
            'draft' => 'als Entwurf gespeichert',
            'feature' => 'hervorgehoben',
            'unfeature' => 'nicht mehr hervorgehoben',
            'move' => 'neu kategorisiert',
            default => 'gelöscht',
        };
        $this->addFlash('success', sprintf('%d Einträge wurden %s.', count($items), $actionLabel));

        return $this->redirectToRoute('admin_video_index');
    }

    /** @param list<Video> $items */
    private function checkSourceStatus(
        array $items,
        VideoRepository $videos,
        EntityManagerInterface $entityManager,
        VideoProviderImportService $providerImport,
        HostingCachePoolProvider $rateLimitCache,
        RateLimitKeyFactory $rateLimitKeys,
        RateLimiterFactory $providerRateLimiter,
        TranslatorInterface $translator,
    ): Response {
        $embeddedItems = array_values(array_filter(
            $items,
            static fn (Video $video): bool => VideoSourceType::Embed === $video->getSourceType(),
        ));
        if ([] === $embeddedItems) {
            $this->addFlash('error', $translator->trans('Die Quellenprüfung ist nur für eingebettete Videos verfügbar.'));

            return $this->redirectToRoute('admin_video_index');
        }

        if (!$rateLimitCache->usesRedisPool()) {
            $this->addFlash('error', $translator->trans('Der gemeinsame Importschutz ist nicht verfügbar. Es wurden keine Videoanbieter angefragt.'));

            return $this->redirectToRoute('admin_video_index');
        }

        $admin = $this->getUser();
        if (null === $admin) {
            throw $this->createAccessDeniedException();
        }
        $limiterKey = $rateLimitKeys->forIdentity($admin->getUserIdentifier(), 'video-source-status');
        $limit = $providerRateLimiter->create($limiterKey)->consume();
        if (!$limit->isAccepted()) {
            $this->addFlash('error', $translator->trans('Das Limit für Quellenprüfungen ist erreicht. Bitte versuche es später erneut.'));

            return $this->redirectToRoute('admin_video_index');
        }

        $attemptedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        /** @var list<array{video: Video, source_url: string, available: bool|null, failed: bool}> $results */
        $results = [];
        foreach ($embeddedItems as $video) {
            $sourceUrl = $video->getSourceUrl();
            try {
                $available = $providerImport->checkSourceAvailability($sourceUrl);
            } catch (VideoProviderImportException) {
                $results[] = ['video' => $video, 'source_url' => $sourceUrl, 'available' => null, 'failed' => true];

                continue;
            }

            $results[] = ['video' => $video, 'source_url' => $sourceUrl, 'available' => $available, 'failed' => false];
        }

        /** @var array{checked: int<0, max>, unavailable: int<0, max>, unsupported: int<0, max>, failed: int<0, max>, changed: int<0, max>} $summary */
        $summary = $entityManager->getConnection()->transactional(function () use ($entityManager, $videos, $results, $attemptedAt): array {
            $summary = ['checked' => 0, 'unavailable' => 0, 'unsupported' => 0, 'failed' => 0, 'changed' => 0];

            foreach ($results as $result) {
                if ($videos->lockExternalSourceUrlForStatusCheck($result['video']->getId()) !== $result['source_url']) {
                    ++$summary['changed'];

                    continue;
                }

                ++$summary['checked'];
                if ($result['failed']) {
                    $result['video']->markExternalSourceCheckFailed($attemptedAt);
                    ++$summary['failed'];
                } else {
                    $status = match ($result['available']) {
                        true => ExternalVideoSourceStatus::Available,
                        false => ExternalVideoSourceStatus::Unavailable,
                        null => ExternalVideoSourceStatus::Unsupported,
                    };
                    $result['video']->markExternalSourceStatus($status, $attemptedAt);
                    if (ExternalVideoSourceStatus::Unavailable === $status) {
                        ++$summary['unavailable'];
                    } elseif (ExternalVideoSourceStatus::Unsupported === $status) {
                        ++$summary['unsupported'];
                    }
                }
            }
            $entityManager->flush();

            return $summary;
        });

        $this->addFlash('success', $translator->trans('Quellenprüfung abgeschlossen: %checked% geprüft, %unavailable% nicht verfügbar, %unsupported% nicht unterstützt und %failed% fehlgeschlagen.', [
            '%checked%' => $summary['checked'],
            '%unavailable%' => $summary['unavailable'],
            '%unsupported%' => $summary['unsupported'],
            '%failed%' => $summary['failed'],
        ]));
        if ($summary['changed'] > 0) {
            $this->addFlash('warning', $translator->trans('Die Quellenprüfung wurde für während des Prüflaufs geänderte Videoquellen verworfen.'));
        }

        return $this->redirectToRoute('admin_video_index');
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
        ContentTagManager $tagManager,
        VideoMediaUploadService $mediaUploads,
        VideoThumbnailUploadService $thumbnailUploads,
        VideoThumbnailImportService $thumbnailImports,
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
        EntityManagerInterface $entityManager,
        SiteSettingsProvider $siteSettings,
        VideoSourceUrlLock $sourceLock,
        VideoSubtitleTrackRepository $subtitleTracks,
        VideoQualitySourceRepository $qualitySources,
        VideoEmbedOriginPolicy $embedOriginPolicy,
        VideoEmbedCodeGenerator $embedCodeGenerator,
        VideoSlugLock $slugLock,
    ): Response {
        return $this->editForm(
            $request,
            null,
            $videos,
            $categories,
            $tagManager,
            $mediaUploads,
            $thumbnailUploads,
            $thumbnailImports,
            $embedResolver,
            $streamResolver,
            $entityManager,
            $siteSettings,
            $sourceLock,
            $subtitleTracks,
            $qualitySources,
            $embedOriginPolicy,
            $embedCodeGenerator,
            $slugLock,
        );
    }

    #[Route(
        '/{id}/edit',
        name: 'edit',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['GET', 'POST'],
    )]
    public function edit(
        string $id,
        Request $request,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
        ContentTagManager $tagManager,
        VideoMediaUploadService $mediaUploads,
        VideoThumbnailUploadService $thumbnailUploads,
        VideoThumbnailImportService $thumbnailImports,
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
        EntityManagerInterface $entityManager,
        SiteSettingsProvider $siteSettings,
        VideoSourceUrlLock $sourceLock,
        VideoSubtitleTrackRepository $subtitleTracks,
        VideoQualitySourceRepository $qualitySources,
        VideoEmbedOriginPolicy $embedOriginPolicy,
        VideoEmbedCodeGenerator $embedCodeGenerator,
        VideoSlugLock $slugLock,
    ): Response {
        $video = $videos->find($id);
        if (!$video instanceof Video) {
            throw $this->createNotFoundException('Video wurde nicht gefunden.');
        }

        return $this->editForm(
            $request,
            $video,
            $videos,
            $categories,
            $tagManager,
            $mediaUploads,
            $thumbnailUploads,
            $thumbnailImports,
            $embedResolver,
            $streamResolver,
            $entityManager,
            $siteSettings,
            $sourceLock,
            $subtitleTracks,
            $qualitySources,
            $embedOriginPolicy,
            $embedCodeGenerator,
            $slugLock,
        );
    }

    private function editForm(
        Request $request,
        ?Video $video,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
        ContentTagManager $tagManager,
        VideoMediaUploadService $mediaUploads,
        VideoThumbnailUploadService $thumbnailUploads,
        VideoThumbnailImportService $thumbnailImports,
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
        EntityManagerInterface $entityManager,
        SiteSettingsProvider $siteSettings,
        VideoSourceUrlLock $sourceLock,
        VideoSubtitleTrackRepository $subtitleTracks,
        VideoQualitySourceRepository $qualitySources,
        VideoEmbedOriginPolicy $embedOriginPolicy,
        VideoEmbedCodeGenerator $embedCodeGenerator,
        VideoSlugLock $slugLock,
    ): Response {
        $isEdit = null !== $video;
        $storedDurationSeconds = $video?->getDurationSeconds();
        $siteTimezone = new \DateTimeZone($siteSettings->current()->getTimezone());
        $storedEmbedOrigins = null !== $video
            ? $embedOriginPolicy->validateStoredOrigins($video->getEmbedAllowedOrigins())
            : [];
        $selectedCategoryIds = array_map(
            static fn (ContentCategory $category): string => $category->getId(),
            $video?->getCategories() ?? [],
        );
        if ([] === $selectedCategoryIds && null !== $video?->getCategory() && '' !== trim($video->getCategory())) {
            $legacyCategory = $categories->findForTypeAndName(ContentCategoryType::Video, $video->getCategory());
            if (null !== $legacyCategory) {
                $selectedCategoryIds[] = $legacyCategory->getId();
            }
        }
        $values = [
            'title' => $video?->getTitle() ?? '',
            'slug' => $video?->getSlug() ?? '',
            'description' => $video?->getDescription() ?? '',
            'category' => $video?->getCategory() ?? '',
            'category_ids' => $selectedCategoryIds,
            'tags' => $video?->getTagNames() ?? '',
            'source_type' => $video?->getSourceType()->value
                ?? (VideoSourceType::tryFrom(self::input($request->query, 'source_type')) ?? VideoSourceType::Embed)->value,
            'source_url' => $video?->getSourceUrl() ?? '',
            'thumbnail_url' => '',
            'duration_minutes' => null === $storedDurationSeconds ? '' : (string) intdiv($storedDurationSeconds, 60),
            'duration_seconds_part' => null === $storedDurationSeconds ? '' : (string) ($storedDurationSeconds % 60),
            'status' => $video?->getStatus()->value ?? PublishingStatus::Draft->value,
            'publish_at' => $video?->getPublishedAt()?->setTimezone($siteTimezone)->format('Y-m-d\\TH:i') ?? '',
            'author_name' => $video?->getAuthorName() ?? 'Redaktion',
            'language_code' => $video?->getLanguageCode() ?? '',
            'featured' => $video?->isFeatured() ?? false,
            'comments_enabled' => $video?->isCommentsEnabled() ?? $siteSettings->current()->isDefaultCommentsEnabled(),
            'members_only' => $video?->isMembersOnly() ?? false,
            'embedding_enabled' => $video?->isEmbeddingEnabled() ?? false,
            'view_count' => (string) ($video?->getViewCount() ?? 0),
            'embed_origins' => implode("\n", $storedEmbedOrigins ?? []),
            'custom_fields' => $video?->getCustomFields() ?? [],
        ];
        $errors = [];
        $tagNames = [];
        $embedOrigins = [];
        /** @var list<ContentCategory> $selectedCategories */
        $selectedCategories = [];
        $storageUnavailable = false;
        /** @var array{object_id: string, mime_type: string, size: int}|null $uploadedMedia */
        $uploadedMedia = null;
        /** @var array{object_id: string, mime_type: string, size: int}|null $uploadedThumbnail */
        $uploadedThumbnail = null;

        if ($request->isMethod('POST')) {
            $token = self::input($request->request, '_token');
            if ('' === $token || !$this->isCsrfTokenValid('admin_video_save', $token)) {
                throw $this->createAccessDeniedException();
            }

            $rawPayload = $request->request->all();
            $allowedFields = [
                '_token', 'title', 'slug', 'description', 'category', 'category_ids_present', 'category_ids', 'tags', 'source_type', 'source_url',
                'duration_seconds', 'duration_minutes', 'duration_seconds_part', 'status', 'publish_at', 'author_name', 'language_code',
                'featured', 'comments_enabled', 'members_only', 'embedding_enabled', 'view_count', 'embed_origins',
                'custom_fields_present', 'custom_fields',
            ];
            if (null === $video) {
                $allowedFields[] = 'thumbnail_url';
            }
            if ([] !== array_diff(array_keys($rawPayload), $allowedFields)) {
                $errors[] = 'Die Anfrage enthält unerwartete Eingabefelder.';
            }
            foreach (['_token', 'title', 'slug', 'description', 'category', 'category_ids_present', 'tags', 'source_type', 'source_url', 'thumbnail_url', 'duration_seconds', 'duration_minutes', 'duration_seconds_part', 'status', 'publish_at', 'author_name', 'language_code', 'view_count', 'embed_origins'] as $field) {
                if (isset($rawPayload[$field]) && !is_string($rawPayload[$field])) {
                    $errors[] = 'Die Eingabe enthält einen ungültigen Wert.';
                }
            }
            foreach (['featured', 'comments_enabled', 'members_only', 'embedding_enabled'] as $field) {
                if (isset($rawPayload[$field]) && '1' !== $rawPayload[$field]) {
                    $errors[] = 'Die Eingabe enthält einen ungültigen Wert.';
                }
            }
            if (array_key_exists('custom_fields', $rawPayload) && !array_key_exists('custom_fields_present', $rawPayload)) {
                $errors[] = 'Die benutzerdefinierten Felder benötigen eine gültige Formularmarkierung.';
            }
            if (array_key_exists('custom_fields_present', $rawPayload) && '1' !== $rawPayload['custom_fields_present']) {
                $errors[] = 'Die benutzerdefinierten Felder benötigen eine gültige Formularmarkierung.';
            }
            $customFields = $video?->getCustomFields() ?? [];
            if ('1' === ($rawPayload['custom_fields_present'] ?? null)) {
                $normalizedCustomFields = CustomFieldSet::normalize($rawPayload['custom_fields'] ?? []);
                if (null === $normalizedCustomFields) {
                    $errors[] = 'Die benutzerdefinierten Videofelder sind ungültig oder überschreiten die zulässigen Grenzen.';
                } else {
                    $customFields = $normalizedCustomFields;
                }
            }
            $uploadedFields = array_keys($request->files->all());
            if ([] !== array_diff($uploadedFields, ['video_file', 'thumbnail_file'])) {
                $errors[] = 'Die Anfrage enthält unerwartete Uploadfelder.';
            }
            $values = [
                'title' => trim(self::input($request->request, 'title')),
                'slug' => array_key_exists('slug', $rawPayload) ? trim(self::input($request->request, 'slug')) : $values['slug'],
                'description' => self::input($request->request, 'description'),
                'category' => trim(self::input($request->request, 'category')),
                'category_ids' => [],
                'tags' => self::input($request->request, 'tags'),
                'source_type' => self::input($request->request, 'source_type'),
                'source_url' => trim(self::input($request->request, 'source_url')),
                'thumbnail_url' => trim(self::input($request->request, 'thumbnail_url')),
                'duration_minutes' => trim(self::input($request->request, 'duration_minutes')),
                'duration_seconds_part' => trim(self::input($request->request, 'duration_seconds_part')),
                'status' => self::input($request->request, 'status'),
                'publish_at' => array_key_exists('publish_at', $rawPayload) ? trim(self::input($request->request, 'publish_at')) : $values['publish_at'],
                'author_name' => array_key_exists('author_name', $rawPayload) ? trim(self::input($request->request, 'author_name')) : $values['author_name'],
                'language_code' => array_key_exists('language_code', $rawPayload) ? trim(self::input($request->request, 'language_code')) : $values['language_code'],
                'featured' => $request->request->has('featured'),
                'comments_enabled' => $request->request->has('comments_enabled'),
                'members_only' => array_key_exists('members_only', $rawPayload) ? $request->request->has('members_only') : $values['members_only'],
                'embedding_enabled' => array_key_exists('embedding_enabled', $rawPayload)
                    ? $request->request->has('embedding_enabled')
                    : ('' !== trim(self::input($request->request, 'embed_origins')) || $values['embedding_enabled']),
                'view_count' => array_key_exists('view_count', $rawPayload) ? trim(self::input($request->request, 'view_count')) : $values['view_count'],
                'embed_origins' => isset($rawPayload['embed_origins']) && is_string($rawPayload['embed_origins'])
                    ? $rawPayload['embed_origins']
                    : '',
                'custom_fields' => $customFields,
            ];
            $categoryIds = [];
            if (array_key_exists('category_ids_present', $rawPayload)) {
                if ('1' !== $rawPayload['category_ids_present'] || array_key_exists('category', $rawPayload)) {
                    $errors[] = 'Die Video-Kategorien wurden mit einem ungültigen Formularwert übermittelt.';
                }
                $rawCategoryIds = $rawPayload['category_ids'] ?? [];
                if (!is_array($rawCategoryIds) || !array_is_list($rawCategoryIds) || count($rawCategoryIds) > 50) {
                    $errors[] = 'Wähle höchstens 50 gültige Video-Kategorien aus.';
                } else {
                    foreach ($rawCategoryIds as $categoryId) {
                        if (!is_string($categoryId) || 1 !== preg_match('/\A[a-f0-9]{32}\z/D', $categoryId)) {
                            $errors[] = 'Die Video-Kategorien enthalten eine ungültige Auswahl.';
                            $categoryIds = [];
                            break;
                        }
                        $categoryIds[] = $categoryId;
                    }
                    if (count(array_unique($categoryIds)) !== count($categoryIds)) {
                        $errors[] = 'Eine Video-Kategorie darf nicht mehrfach ausgewählt werden.';
                        $categoryIds = [];
                    }
                }
            } elseif (array_key_exists('category_ids', $rawPayload)) {
                $errors[] = 'Die Video-Kategorien benötigen eine gültige Formularmarkierung.';
            } elseif ('' !== $values['category']) {
                $legacyCategory = $categories->findForTypeAndName(ContentCategoryType::Video, $values['category']);
                if (null === $legacyCategory) {
                    $errors[] = 'Bitte wähle eine vorhandene Video-Kategorie.';
                } else {
                    $categoryIds[] = $legacyCategory->getId();
                }
            }
            $values['category_ids'] = $categoryIds;
            $selectedCategoryIds = $categoryIds;
            $categoriesById = [];
            foreach ($categories->findVideoCategoriesByIds($categoryIds) as $selectedCategory) {
                $categoriesById[$selectedCategory->getId()] = $selectedCategory;
            }
            if (count($categoriesById) !== count($categoryIds)) {
                $errors[] = 'Mindestens eine ausgewählte Kategorie ist nicht verfügbar.';
            } else {
                foreach ($categoryIds as $categoryId) {
                    $selectedCategories[] = $categoriesById[$categoryId];
                }
            }
            $primarySelectedCategory = $selectedCategories[0] ?? null;
            $values['category'] = $primarySelectedCategory?->getName() ?? '';
            if (array_key_exists('embed_origins', $rawPayload) && !is_string($rawPayload['embed_origins'])) {
                $errors[] = 'Die Freigabeliste für Videoeinbettungen muss aus Text bestehen.';
            }
            if (array_key_exists('duration_seconds', $rawPayload)
                && (array_key_exists('duration_minutes', $rawPayload) || array_key_exists('duration_seconds_part', $rawPayload))
            ) {
                $errors[] = 'Die Medienlaufzeit darf nur in einem einheitlichen Format übermittelt werden.';
            }
            if ((array_key_exists('duration_minutes', $rawPayload) && !is_string($rawPayload['duration_minutes']))
                || (array_key_exists('duration_seconds_part', $rawPayload) && !is_string($rawPayload['duration_seconds_part']))
                || (array_key_exists('duration_seconds', $rawPayload) && !is_string($rawPayload['duration_seconds']))
            ) {
                $errors[] = 'Die Medienlaufzeit muss aus ganzen Zahlen bestehen.';
            }
            $parsedEmbedOrigins = $embedOriginPolicy->parseInput($values['embed_origins']);
            if (null === $parsedEmbedOrigins) {
                $errors[] = 'Die Freigabeliste darf höchstens 20 gültige HTTPS-Ursprünge ohne Pfad enthalten.';
            } else {
                $embedOrigins = $parsedEmbedOrigins;
            }

            $uploadedField = $request->files->get('video_file');
            if (null !== $uploadedField && !($uploadedField instanceof UploadedFile)) {
                $errors[] = 'Bitte wähle genau eine gültige Mediendatei aus.';
                $uploadedFile = null;
            } else {
                $uploadedFile = $uploadedField instanceof UploadedFile ? $uploadedField : null;
            }
            $thumbnailField = $request->files->get('thumbnail_file');
            if (null !== $thumbnailField && !($thumbnailField instanceof UploadedFile)) {
                $errors[] = 'Bitte wähle genau ein gültiges Vorschaubild aus.';
                $thumbnailFile = null;
            } else {
                $thumbnailFile = $thumbnailField instanceof UploadedFile ? $thumbnailField : null;
            }
            if (null !== $video && $thumbnailFile instanceof UploadedFile) {
                $errors[] = 'Bitte verwende beim Bearbeiten den gesonderten Vorschaubildbereich.';
            }
            if (null !== $video && '' !== $values['thumbnail_url']) {
                $errors[] = 'Bitte verwende beim Bearbeiten den gesonderten Vorschaubildbereich.';
            }
            if ('' !== $values['thumbnail_url']
                && (strlen($values['thumbnail_url']) > 2048
                    || 1 === preg_match('/[\x00-\x20\x7f]/', $values['thumbnail_url'])
                    || !Video::isSafeHttpsSource($values['thumbnail_url']))
            ) {
                $errors[] = 'Die Vorschaubild-Adresse muss eine gültige HTTPS-Adresse ohne Zugangsdaten sein.';
            }
            if ($thumbnailFile instanceof UploadedFile && '' !== $values['thumbnail_url']) {
                $errors[] = 'Bitte wähle entweder eine Bilddatei oder eine Vorschaubild-Adresse aus.';
            }

            $sourceType = VideoSourceType::tryFrom($values['source_type']);
            $status = PublishingStatus::tryFrom($values['status']);
            try {
                $tagNames = ContentTagManager::parseNames($values['tags']);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
            if (false === preg_match('//u', $values['title'])
                || '' === $values['title']
                || mb_strlen($values['title'], 'UTF-8') > 180
                || 1 === preg_match('/\p{Cc}/u', $values['title'])
            ) {
                $errors[] = 'Der Titel ist erforderlich und darf höchstens 180 Zeichen lang sein.';
            }
            $slug = '' === $values['slug'] ? self::slugFromTitle($values['title']) : $values['slug'];
            if (mb_strlen($slug, 'UTF-8') > 200 || 1 !== preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug)) {
                $errors[] = 'Der Permalink darf nur Kleinbuchstaben, Ziffern und einzelne Bindestriche enthalten.';
            }
            $existingSlugVideo = '' === $slug ? null : $videos->findOneBySlug($slug);
            if (null !== $existingSlugVideo
                && (null === $video || $existingSlugVideo->getId() !== $video->getId())
            ) {
                $errors[] = 'Für diesen Video-Permalink gibt es bereits ein Video.';
            }
            if (false === preg_match('//u', $values['author_name'])
                || '' === $values['author_name']
                || mb_strlen($values['author_name'], 'UTF-8') > 120
                || 1 === preg_match('/\p{Cc}/u', $values['author_name'])
            ) {
                $errors[] = 'Die Einsenderangabe ist erforderlich und darf höchstens 120 Zeichen lang sein.';
            }
            if ('' !== $values['language_code']
                && (false === preg_match('//u', $values['language_code'])
                    || mb_strlen($values['language_code'], 'UTF-8') > 35
                    || 1 !== preg_match('/\A[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*\z/', $values['language_code']))
            ) {
                $errors[] = 'Der Sprachcode muss ein gültiger BCP-47-Code sein, zum Beispiel de oder en-US.';
            }
            if (mb_strlen($values['description']) > 100000) {
                $errors[] = 'Die Beschreibung darf höchstens 100.000 Zeichen lang sein.';
            }
            if (mb_strlen($values['category']) > 100) {
                $errors[] = 'Die Kategorie darf höchstens 100 Zeichen lang sein.';
            }
            $durationSeconds = null;
            $hasMinuteParts = array_key_exists('duration_minutes', $rawPayload)
                || array_key_exists('duration_seconds_part', $rawPayload);
            if ($hasMinuteParts) {
                $minutes = $values['duration_minutes'];
                $secondsPart = $values['duration_seconds_part'];
                $validMinutes = '' === $minutes
                    || (ctype_digit($minutes) && strlen($minutes) <= 8 && (int) $minutes <= 35791394);
                $validSecondsPart = '' === $secondsPart
                    || (ctype_digit($secondsPart) && strlen($secondsPart) <= 2 && (int) $secondsPart <= 59);
                if (!$validMinutes || !$validSecondsPart) {
                    $errors[] = 'Die Laufzeit muss aus höchstens 35.791.394 Minuten und 0 bis 59 Sekunden bestehen.';
                } else {
                    $totalSeconds = (('' === $minutes ? 0 : (int) $minutes) * 60)
                        + ('' === $secondsPart ? 0 : (int) $secondsPart);
                    if ($totalSeconds > 2147483647) {
                        $errors[] = 'Die Medienlaufzeit darf höchstens 2.147.483.647 Sekunden betragen.';
                    } elseif ('' !== $minutes || '' !== $secondsPart) {
                        $durationSeconds = $totalSeconds;
                    }
                }
            } else {
                $legacyDurationSeconds = trim(self::input($request->request, 'duration_seconds'));
                if ('' !== $legacyDurationSeconds) {
                    if (!ctype_digit($legacyDurationSeconds)
                        || strlen($legacyDurationSeconds) > 10
                        || (int) $legacyDurationSeconds > 2147483647
                    ) {
                        $errors[] = 'Die Medienlaufzeit muss eine ganze Zahl von 0 bis 2.147.483.647 Sekunden sein.';
                    } else {
                        $durationSeconds = (int) $legacyDurationSeconds;
                        $values['duration_minutes'] = (string) intdiv($durationSeconds, 60);
                        $values['duration_seconds_part'] = (string) ($durationSeconds % 60);
                    }
                }
            }
            if ('' !== $values['category']
                && null === $categories->findForTypeAndName(ContentCategoryType::Video, $values['category'])
            ) {
                $errors[] = 'Bitte wähle eine vorhandene Video-Kategorie.';
            }
            if (null === $sourceType) {
                $errors[] = 'Bitte wähle eine gültige Videoquelle.';
            } elseif (VideoSourceType::File === $sourceType) {
                if (null === $uploadedFile && (null === $video || null === $video->getUploadedObjectId())) {
                    $errors[] = 'Für einen neuen Datei-Upload ist eine geprüfte Medien-Datei erforderlich.';
                }
            } elseif (VideoSourceType::Stream === $sourceType) {
                $unchangedExistingStream = null !== $video
                    && VideoSourceType::Stream === $video->getSourceType()
                    && $video->getSourceUrl() === $values['source_url'];
                if (null === $streamResolver->resolve($values['source_url']) && !$unchangedExistingStream) {
                    $errors[] = 'Ein Stream benötigt eine HTTPS-Mediendatei oder HLS-Wiedergabeliste auf einem ausdrücklich freigegebenen Host.';
                }
                if (null !== $uploadedFile) {
                    $errors[] = 'Eine Datei kann nur mit der Quellart Datei-Upload gespeichert werden.';
                }
            } else {
                if (!Video::isSafeHttpsSource($values['source_url'])) {
                    $errors[] = 'Die Videoquelle muss eine gültige HTTPS-Adresse ohne Zugangsdaten sein.';
                } elseif (VideoSourceType::Embed === $sourceType && null === $embedResolver->resolve($values['source_url'])) {
                    $errors[] = 'Für eine Einbettung ist nur eine gültige Video-URL von YouTube, Vimeo, Dailymotion, Facebook, Google Drive, Rutube, VK oder Youku zulässig.';
                }
                if (null !== $uploadedFile) {
                    $errors[] = 'Eine Datei kann nur mit der Quellart Datei-Upload gespeichert werden.';
                }
            }
            if (null === $status) {
                $errors[] = 'Bitte wähle einen gültigen Veröffentlichungsstatus.';
            }
            $publicationAt = null;
            if (PublishingStatus::Published === $status) {
                if ('' === $values['publish_at']) {
                    $publicationAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                } else {
                    $parsedPublicationAt = strlen($values['publish_at']) <= 16
                        ? \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $values['publish_at'], $siteTimezone)
                        : false;
                    $dateErrors = false === $parsedPublicationAt ? false : \DateTimeImmutable::getLastErrors();
                    if (false === $parsedPublicationAt
                        || (false !== $dateErrors && (0 !== $dateErrors['warning_count'] || 0 !== $dateErrors['error_count']))
                        || $parsedPublicationAt->format('Y-m-d\\TH:i') !== $values['publish_at']
                    ) {
                        $errors[] = 'Bitte gib einen gültigen Veröffentlichungszeitpunkt ein.';
                    } else {
                        $publicationAt = $parsedPublicationAt->setTimezone(new \DateTimeZone('UTC'));
                    }
                }
            } else {
                // A published video's old schedule is present in the edit form;
                // changing it to a draft must discard that now-irrelevant value.
                $values['publish_at'] = '';
            }
            $viewCount = ctype_digit($values['view_count'])
                && strlen($values['view_count']) <= 10
                && (int) $values['view_count'] <= 2147483647
                    ? (int) $values['view_count']
                    : null;
            if (null === $viewCount) {
                $errors[] = 'Die Aufrufzahl muss eine ganze Zahl von 0 bis 2.147.483.647 sein.';
            }
            if (!$values['embedding_enabled'] && [] !== $embedOrigins) {
                $errors[] = 'Trage Einbettungsseiten nur ein, wenn die Einbettung für dieses Video erlaubt ist.';
            }
            if ($values['members_only'] && [] !== $embedOrigins) {
                $errors[] = 'Mitgliedervideos können nicht für externe Websites freigegeben werden.';
            }
            if ([] !== $embedOrigins && null !== $sourceType && null !== $status && PublishingStatus::Published === $status) {
                $canEmbedSource = match ($sourceType) {
                    VideoSourceType::File => null !== $uploadedFile || null !== $video?->getUploadedObjectId(),
                    VideoSourceType::Stream => null !== $streamResolver->resolve($values['source_url']),
                    VideoSourceType::Embed => null !== $embedResolver->resolve($values['source_url']),
                    VideoSourceType::Url => false,
                };
                if (!$canEmbedSource) {
                    $errors[] = 'Nur veröffentlichte Datei-Uploads, freigegebene Streams und geprüfte Provider-Einbettungen können extern freigegeben werden.';
                }
            }

            if ([] === $errors && VideoSourceType::File === $sourceType && $uploadedFile instanceof UploadedFile) {
                try {
                    $uploadedMedia = $mediaUploads->store($uploadedFile);
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = $exception->getMessage();
                } catch (PrivateStorageOperationFailed) {
                    $errors[] = 'Der sichere private Dateispeicher ist derzeit nicht verfügbar; der Upload wurde gesperrt.';
                    $storageUnavailable = true;
                }
            }
            if ([] === $errors && $thumbnailFile instanceof UploadedFile) {
                try {
                    $uploadedThumbnail = $thumbnailUploads->store($thumbnailFile);
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = $exception->getMessage();
                } catch (PrivateStorageOperationFailed) {
                    $errors[] = 'Der sichere private Bildspeicher ist derzeit nicht verfügbar; das Vorschaubild wurde nicht gespeichert.';
                    $storageUnavailable = true;
                }
            }
            if ([] === $errors && '' !== $values['thumbnail_url']) {
                try {
                    $uploadedThumbnail = $thumbnailImports->storeFromUrl(
                        $values['thumbnail_url'],
                        new VideoThumbnailImportBudget(4_194_304, 8.0),
                    );
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = $exception->getMessage();
                } catch (PrivateStorageOperationFailed) {
                    $errors[] = 'Der sichere private Bildspeicher ist derzeit nicht verfügbar; das Vorschaubild wurde nicht gespeichert.';
                    $storageUnavailable = true;
                }
            }
            if ([] !== $errors) {
                if (null !== $uploadedMedia) {
                    try {
                        $mediaUploads->delete($uploadedMedia['object_id']);
                    } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                        $this->addFlash('warning', 'Ein abgelehnter privater Upload konnte nicht bereinigt werden.');
                    }
                    $uploadedMedia = null;
                }
                if (null !== $uploadedThumbnail) {
                    try {
                        $thumbnailUploads->delete($uploadedThumbnail['object_id']);
                    } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                        $this->addFlash('warning', 'Ein abgelehntes privates Vorschaubild konnte nicht bereinigt werden.');
                    }
                    $uploadedThumbnail = null;
                }
            }

            if ([] === $errors && null !== $sourceType && null !== $status) {
                $isNew = null === $video;
                $previousObjectId = null;
                $viewCountValue = $viewCount ?? 0;
                if ($isNew) {
                    $video = new Video(
                        $values['title'],
                        $values['description'],
                        '' === $values['category'] ? null : $values['category'],
                        $sourceType,
                        $values['source_url'],
                        $status,
                        $values['featured'],
                        $values['comments_enabled'],
                    );
                }
                try {
                    $saveResult = $entityManager->getConnection()->transactional(function (Connection $connection) use (
                        $sourceType,
                        $sourceLock,
                        $slugLock,
                        $categories,
                        $selectedCategoryIds,
                        $values,
                        $slug,
                        $durationSeconds,
                        $status,
                        $publicationAt,
                        $viewCountValue,
                        $embedOrigins,
                        $uploadedMedia,
                        $uploadedThumbnail,
                        $isNew,
                        $videos,
                        &$video,
                        &$previousObjectId,
                        $tagManager,
                        $tagNames,
                        $entityManager,
                    ): string {
                        $lockedCategoriesById = [];
                        foreach ($categories->findVideoCategoriesByIdsForUpdate($selectedCategoryIds) as $lockedCategory) {
                            $lockedCategoriesById[$lockedCategory->getId()] = $lockedCategory;
                        }
                        if (count($lockedCategoriesById) !== count($selectedCategoryIds)) {
                            return 'missing_category';
                        }
                        $lockedCategories = [];
                        foreach ($selectedCategoryIds as $selectedCategoryId) {
                            $lockedCategories[] = $lockedCategoriesById[$selectedCategoryId];
                        }
                        $primaryCategoryName = isset($lockedCategories[0]) ? $lockedCategories[0]->getName() : '';

                        $slugLock->lock($connection, $slug);
                        if (VideoSourceType::File !== $sourceType) {
                            $sourceLock->lock($connection, [$values['source_url']]);
                        }

                        if (!$isNew) {
                            $currentVideo = $videos->findByIdForUpdate($video->getId());
                            if (!$currentVideo instanceof Video) {
                                return 'missing_video';
                            }

                            $video = $currentVideo;
                        }

                        $existingSlugVideo = $videos->findOneBySlug($slug);
                        if (null !== $existingSlugVideo && $existingSlugVideo->getId() !== $video->getId()) {
                            return 'duplicate_slug';
                        }

                        if (VideoSourceType::File !== $sourceType) {
                            $existingVideo = $videos->findBySourceUrl($values['source_url']);
                            if (null !== $existingVideo && $existingVideo->getId() !== $video->getId()) {
                                return 'duplicate_source';
                            }
                        }

                        if (VideoSourceType::File === $sourceType
                            && null === $uploadedMedia
                            && null === $video->getUploadedObjectId()
                        ) {
                            return 'missing_media';
                        }

                        $previousObjectId = $video->getUploadedObjectId();
                        $video->revise(
                            $values['title'],
                            $values['description'],
                            '' === $primaryCategoryName ? null : $primaryCategoryName,
                            $sourceType,
                            $values['source_url'],
                            $status,
                            $values['featured'],
                            $values['comments_enabled'],
                        );
                        $video->updateEditorialMetadata(
                            $slug,
                            $values['author_name'],
                            $values['members_only'],
                            $values['embedding_enabled'],
                            '' === $values['language_code'] ? null : $values['language_code'],
                        );
                        $video->setPublicationAt($publicationAt);
                        $video->setViewCount($viewCountValue);
                        $video->setDurationSeconds($durationSeconds);
                        $video->replaceCategories($lockedCategories);
                        $video->replaceCustomFields($values['custom_fields']);
                        $video->setEmbedAllowedOrigins(
                            PublishingStatus::Published === $status && $values['embedding_enabled'] ? $embedOrigins : [],
                        );
                        if (null !== $uploadedMedia) {
                            $video->attachUploadedMedia(
                                $uploadedMedia['object_id'],
                                $uploadedMedia['mime_type'],
                                $uploadedMedia['size'],
                            );
                        }
                        if (null !== $uploadedThumbnail) {
                            $video->attachThumbnail(
                                $uploadedThumbnail['object_id'],
                                $uploadedThumbnail['mime_type'],
                                $uploadedThumbnail['size'],
                            );
                        }
                        $video->replaceTags($tagManager->resolve($tagNames));
                        $entityManager->persist($video);
                        $entityManager->flush();

                        return 'saved';
                    });
                } catch (\Throwable $exception) {
                    if (null !== $uploadedMedia) {
                        try {
                            $mediaUploads->delete($uploadedMedia['object_id']);
                        } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                            $this->addFlash('warning', 'Ein neuer privater Upload konnte nach dem Speicherfehler nicht bereinigt werden.');
                        }
                    }
                    if (null !== $uploadedThumbnail) {
                        try {
                            $thumbnailUploads->delete($uploadedThumbnail['object_id']);
                        } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                            $this->addFlash('warning', 'Ein neues privates Vorschaubild konnte nach dem Speicherfehler nicht bereinigt werden.');
                        }
                    }

                    throw $exception;
                }

                if ('saved' !== $saveResult) {
                    if (null !== $uploadedMedia) {
                        try {
                            $mediaUploads->delete($uploadedMedia['object_id']);
                        } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                            $this->addFlash('warning', 'Ein abgelehnter privater Upload konnte nicht bereinigt werden.');
                        }
                    }
                    if (null !== $uploadedThumbnail) {
                        try {
                            $thumbnailUploads->delete($uploadedThumbnail['object_id']);
                        } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                            $this->addFlash('warning', 'Ein abgelehntes privates Vorschaubild konnte nicht bereinigt werden.');
                        }
                    }

                    $errorMessages = [
                        'duplicate_source' => 'Diese Videoquelle ist bereits vorhanden.',
                        'duplicate_slug' => 'Für diesen Video-Permalink gibt es bereits ein Video.',
                        'missing_media' => 'Für einen Datei-Upload ist weiterhin eine geprüfte Mediendatei erforderlich.',
                        'missing_video' => 'Das Video wurde zwischenzeitlich entfernt. Bitte lade die Seite neu.',
                        'missing_category' => 'Mindestens eine ausgewählte Kategorie wurde zwischenzeitlich geändert. Bitte lade die Seite neu.',
                    ];
                    $errors[] = $errorMessages[$saveResult];
                } else {
                    $mediaCleanupFailed = false;
                    if (null !== $previousObjectId && $previousObjectId !== $video->getUploadedObjectId()) {
                        try {
                            $mediaUploads->delete($previousObjectId);
                        } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                            $mediaCleanupFailed = true;
                        }
                    }
                    if ($mediaCleanupFailed) {
                        $this->addFlash('warning', 'Die frühere private Medien-Datei konnte nicht bereinigt werden.');
                    }

                    $this->addFlash('success', 'Video wurde gespeichert.');

                    return $this->redirectToRoute('admin_video_index');
                }
            }
        }

        $qualitySourceSupported = $isEdit && match ($video->getSourceType()) {
            VideoSourceType::File => !$video->isAudio(),
            VideoSourceType::Stream => null !== ($currentStream = $streamResolver->resolve($video->getSourceUrl()))
                && !$currentStream->isAudio()
                && !$currentStream->isHls(),
            default => false,
        };

        return $this->render('admin/videos/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'categories' => $categories->findForAdmin(ContentCategoryType::Video),
            'is_edit' => $isEdit,
            'current_media_type' => $video?->getUploadedMimeType(),
            'video_view_count' => $video?->getViewCount(),
            'video_id' => $isEdit ? $video->getId() : null,
            'has_thumbnail' => $isEdit && null !== $video->getThumbnailObjectId(),
            'subtitle_tracks' => $isEdit ? $subtitleTracks->findForVideo($video) : [],
            'subtitle_supported' => $isEdit && in_array($video->getSourceType(), [VideoSourceType::File, VideoSourceType::Stream], true),
            'quality_sources' => $isEdit ? $qualitySources->findForVideo($video) : [],
            'quality_options' => VideoQuality::cases(),
            'quality_source_supported' => $qualitySourceSupported,
            'stream_configured' => $streamResolver->isConfigured(),
            'embed_code' => $request->isMethod('GET') && null !== $video
                ? $embedCodeGenerator->generate($video)
                : null,
        ], new Response(status: $storageUnavailable ? Response::HTTP_SERVICE_UNAVAILABLE : ([] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY)));
    }

    /**
     * @template TInput of string|int|float|bool|null
     *
     * @param InputBag<TInput> $bag
     */
    private static function input(InputBag $bag, string $key): string
    {
        $value = $bag->all()[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private static function slugFromTitle(string $title): string
    {
        if (false === preg_match('//u', $title)) {
            return 'video';
        }

        $slug = new AsciiSlugger('en')->slug(trim($title))->lower()->toString();
        $slug = rtrim(mb_substr($slug, 0, 200, 'UTF-8'), '-');

        return '' === $slug ? 'video' : $slug;
    }
}
