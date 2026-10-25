<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\CommentContentType;
use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentCategoryRepository;
use App\Repository\ContentCommentRepository;
use App\Repository\ContentTagRepository;
use App\Repository\VideoRepository;
use App\Repository\VideoSubtitleTrackRepository;
use App\Service\ContentTagManager;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\SiteSettingsProvider;
use App\Service\VideoMediaUploadService;
use App\Service\VideoSourceUrlLock;
use App\Service\VideoSubtitleUploadService;
use App\Service\VideoThumbnailUploadService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        if (!in_array($action, ['publish', 'draft', 'feature', 'unfeature', 'move', 'delete'], true)) {
            $this->addFlash('error', 'Bitte wähle eine gültige Sammelaktion aus.');

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

        $uploadedObjectIds = [];
        $thumbnailObjectIds = [];
        $subtitleObjectIds = [];
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
                }
            }
        }

        $entityManager->getConnection()->transactional(function () use ($items, $action, $comments, $entityManager, $targetCategoryName): void {
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

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
        ContentTagManager $tagManager,
        VideoMediaUploadService $mediaUploads,
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
        EntityManagerInterface $entityManager,
        SiteSettingsProvider $siteSettings,
        VideoSourceUrlLock $sourceLock,
        VideoSubtitleTrackRepository $subtitleTracks,
    ): Response {
        return $this->editForm($request, null, $videos, $categories, $tagManager, $mediaUploads, $embedResolver, $streamResolver, $entityManager, $siteSettings, $sourceLock, $subtitleTracks);
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
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
        EntityManagerInterface $entityManager,
        SiteSettingsProvider $siteSettings,
        VideoSourceUrlLock $sourceLock,
        VideoSubtitleTrackRepository $subtitleTracks,
    ): Response {
        $video = $videos->find($id);
        if (!$video instanceof Video) {
            throw $this->createNotFoundException('Video wurde nicht gefunden.');
        }

        return $this->editForm($request, $video, $videos, $categories, $tagManager, $mediaUploads, $embedResolver, $streamResolver, $entityManager, $siteSettings, $sourceLock, $subtitleTracks);
    }

    private function editForm(
        Request $request,
        ?Video $video,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
        ContentTagManager $tagManager,
        VideoMediaUploadService $mediaUploads,
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
        EntityManagerInterface $entityManager,
        SiteSettingsProvider $siteSettings,
        VideoSourceUrlLock $sourceLock,
        VideoSubtitleTrackRepository $subtitleTracks,
    ): Response {
        $isEdit = null !== $video;
        $values = [
            'title' => $video?->getTitle() ?? '',
            'description' => $video?->getDescription() ?? '',
            'category' => $video?->getCategory() ?? '',
            'tags' => $video?->getTagNames() ?? '',
            'source_type' => $video?->getSourceType()->value ?? VideoSourceType::Url->value,
            'source_url' => $video?->getSourceUrl() ?? '',
            'status' => $video?->getStatus()->value ?? PublishingStatus::Draft->value,
            'featured' => $video?->isFeatured() ?? false,
            'comments_enabled' => $video?->isCommentsEnabled() ?? $siteSettings->current()->isDefaultCommentsEnabled(),
        ];
        $errors = [];
        $tagNames = [];
        $storageUnavailable = false;
        /** @var array{object_id: string, mime_type: string, size: int}|null $uploadedMedia */
        $uploadedMedia = null;

        if ($request->isMethod('POST')) {
            $token = self::input($request->request, '_token');
            if ('' === $token || !$this->isCsrfTokenValid('admin_video_save', $token)) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'title' => trim(self::input($request->request, 'title')),
                'description' => self::input($request->request, 'description'),
                'category' => trim(self::input($request->request, 'category')),
                'tags' => self::input($request->request, 'tags'),
                'source_type' => self::input($request->request, 'source_type'),
                'source_url' => trim(self::input($request->request, 'source_url')),
                'status' => self::input($request->request, 'status'),
                'featured' => $request->request->has('featured'),
                'comments_enabled' => $request->request->has('comments_enabled'),
            ];

            $uploadedField = $request->files->get('video_file');
            if (null !== $uploadedField && !($uploadedField instanceof UploadedFile)) {
                $errors[] = 'Bitte wähle genau eine gültige Videodatei aus.';
                $uploadedFile = null;
            } else {
                $uploadedFile = $uploadedField instanceof UploadedFile ? $uploadedField : null;
            }

            $sourceType = VideoSourceType::tryFrom($values['source_type']);
            $status = PublishingStatus::tryFrom($values['status']);
            try {
                $tagNames = ContentTagManager::parseNames($values['tags']);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
            if ('' === $values['title'] || mb_strlen($values['title']) > 180) {
                $errors[] = 'Der Titel ist erforderlich und darf höchstens 180 Zeichen lang sein.';
            }
            if (mb_strlen($values['description']) > 100000) {
                $errors[] = 'Die Beschreibung darf höchstens 100.000 Zeichen lang sein.';
            }
            if (mb_strlen($values['category']) > 100) {
                $errors[] = 'Die Kategorie darf höchstens 100 Zeichen lang sein.';
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
                    $errors[] = 'Für ein neues Datei-Video ist eine geprüfte Videodatei erforderlich.';
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
                    $errors[] = 'Für eine Einbettung ist nur eine gültige YouTube- oder Vimeo-Video-URL zulässig.';
                }
                if (null !== $uploadedFile) {
                    $errors[] = 'Eine Datei kann nur mit der Quellart Datei-Upload gespeichert werden.';
                }
            }
            if (null === $status) {
                $errors[] = 'Bitte wähle einen gültigen Veröffentlichungsstatus.';
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

            if ([] === $errors && null !== $sourceType) {
                $previousObjectId = $video?->getUploadedObjectId();
                if (null === $video) {
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
                } else {
                    $video->revise(
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
                if (null !== $uploadedMedia) {
                    $video->attachUploadedMedia(
                        $uploadedMedia['object_id'],
                        $uploadedMedia['mime_type'],
                        $uploadedMedia['size'],
                    );
                }
                try {
                    $saved = $entityManager->getConnection()->transactional(function (Connection $connection) use (
                        $sourceType,
                        $sourceLock,
                        $values,
                        $videos,
                        $video,
                        $tagManager,
                        $tagNames,
                        $entityManager,
                    ): bool {
                        if (VideoSourceType::File !== $sourceType) {
                            $sourceLock->lock($connection, [$values['source_url']]);
                            $existingVideo = $videos->findBySourceUrl($values['source_url']);
                            if (null !== $existingVideo && $existingVideo->getId() !== $video->getId()) {
                                return false;
                            }
                        }

                        $video->replaceTags($tagManager->resolve($tagNames));
                        $entityManager->persist($video);
                        $entityManager->flush();

                        return true;
                    });
                } catch (\Throwable $exception) {
                    if (null !== $uploadedMedia) {
                        try {
                            $mediaUploads->delete($uploadedMedia['object_id']);
                        } catch (PrivateStorageOperationFailed|\InvalidArgumentException) {
                            $this->addFlash('warning', 'Ein neuer privater Upload konnte nach dem Speicherfehler nicht bereinigt werden.');
                        }
                    }

                    throw $exception;
                }

                if (!$saved) {
                    $errors[] = 'Diese Videoquelle ist bereits vorhanden.';
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
                        $this->addFlash('warning', 'Die frühere private Videodatei konnte nicht bereinigt werden.');
                    }

                    $this->addFlash('success', 'Video wurde gespeichert.');

                    return $this->redirectToRoute('admin_video_index');
                }
            }
        }

        return $this->render('admin/videos/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'categories' => $categories->findForAdmin(ContentCategoryType::Video),
            'is_edit' => $isEdit,
            'current_media_type' => $video?->getUploadedMimeType(),
            'video_id' => $isEdit ? $video->getId() : null,
            'has_thumbnail' => $isEdit && null !== $video->getThumbnailObjectId(),
            'subtitle_tracks' => $isEdit ? $subtitleTracks->findForVideo($video) : [],
            'subtitle_supported' => $isEdit && in_array($video->getSourceType(), [VideoSourceType::File, VideoSourceType::Stream], true),
            'stream_configured' => $streamResolver->isConfigured(),
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
}
