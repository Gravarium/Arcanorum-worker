<?php

declare(strict_types=1);

namespace App\Module\News\Controller;

use App\Content\CustomFieldSet;
use App\Entity\AdminUser;
use App\Entity\CommentContentType;
use App\Entity\ContentCategoryType;
use App\Entity\NewsArticle;
use App\Entity\PrivateObjectDeletionJob;
use App\Entity\PublishingStatus;
use App\Module\News\Service\NewsArticleRevisionManager;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentCategoryRepository;
use App\Repository\ContentCommentRepository;
use App\Repository\ContentInlineImageRepository;
use App\Repository\ContentTagRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\NewsArticleRevisionRepository;
use App\Repository\PrivateObjectDeletionJobRepository;
use App\Service\ContentInlineImageManager;
use App\Service\ContentTagManager;
use App\Service\RichTextSanitizer;
use App\Service\SiteSettingsProvider;
use App\Service\VideoThumbnailUploadService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/news', name: 'admin_news_')]
#[IsGranted('ROLE_CONTENT_EDITOR')]
final class NewsAdminController extends AbstractController
{
    private const int ADMIN_PAGE_SIZE = 25;
    private const int REVISION_PAGE_SIZE = 25;

    public function __construct(
        private readonly VideoThumbnailUploadService $thumbnailUploads,
        private readonly PrivateObjectDeletionJobRepository $deletionJobs,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        NewsArticleRepository $articles,
        ContentCategoryRepository $categories,
        ContentTagRepository $tags,
        SiteSettingsProvider $siteSettings,
    ): Response {
        $search = mb_substr(trim(self::input($request->query, 'q')), 0, 180);
        $status = PublishingStatus::tryFrom(self::input($request->query, 'status'));
        $category = mb_substr(trim(self::input($request->query, 'category')), 0, 100);
        $tag = mb_substr(trim(self::input($request->query, 'tag')), 0, 100);
        $sort = self::input($request->query, 'sort');
        if (!in_array($sort, ['created', 'title', 'status'], true)) {
            $sort = 'created';
        }
        $direction = 'asc' === strtolower(self::input($request->query, 'direction')) ? 'asc' : 'desc';
        $requestedPage = self::input($request->query, 'page');
        $page = ctype_digit($requestedPage) ? (int) $requestedPage : 1;
        $page = max(1, min(100000, $page));

        $categoryFilter = '' === $category ? null : $category;
        $tagFilter = '' === $tag ? null : $tag;
        $total = $articles->countForAdmin($search, $status, $categoryFilter, $tagFilter);
        $pageCount = max(1, (int) ceil($total / self::ADMIN_PAGE_SIZE));
        $page = min($page, $pageCount);
        $items = $articles->findForAdmin(
            search: $search,
            status: $status,
            limit: self::ADMIN_PAGE_SIZE,
            offset: ($page - 1) * self::ADMIN_PAGE_SIZE,
            category: $categoryFilter,
            tag: $tagFilter,
            sort: $sort,
            direction: $direction,
        );

        return $this->render('admin/news/index.html.twig', [
            'articles' => $items,
            'search' => $search,
            'status' => $status instanceof PublishingStatus ? $status->value : '',
            'category' => $category,
            'categories' => $categories->findForAdmin(ContentCategoryType::News),
            'tag' => $tag,
            'tags' => $this->isGranted('ROLE_ADMIN') ? $tags->findForAdmin() : $tags->findForNewsAdmin(),
            'sort' => $sort,
            'direction' => $direction,
            'page' => $page,
            'page_count' => $pageCount,
            'total' => $total,
            'now' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'site_timezone' => $siteSettings->current()->getTimezone(),
        ]);
    }

    #[Route('/bulk', name: 'bulk', methods: ['POST'])]
    public function bulk(
        Request $request,
        NewsArticleRepository $articles,
        ContentCommentRepository $comments,
        NewsArticleRevisionManager $revisions,
        NewsArticleRevisionRepository $articleRevisions,
        PrivateObjectDeletionJobRepository $deletionJobs,
        ContentInlineImageRepository $inlineImages,
        EntityManagerInterface $entityManager,
        TranslatorInterface $translator,
    ): Response {
        $token = self::input($request->request, '_token');
        if ('' === $token || !$this->isCsrfTokenValid('admin_news_bulk', $token)) {
            throw $this->createAccessDeniedException();
        }

        $payload = $request->request->all();
        $rawIds = $payload['ids'] ?? [];
        if (!is_array($rawIds)) {
            $this->addFlash('error', 'Bitte wähle gültige Einträge aus.');

            return $this->redirectToRoute('admin_news_index');
        }

        $ids = [];
        $seenIds = [];
        foreach ($rawIds as $rawId) {
            if (!is_string($rawId) || 1 !== preg_match('/\\A[a-f0-9]{32}\\z/D', $rawId)) {
                $this->addFlash('error', 'Die Auswahl enthält eine ungültige Kennung.');

                return $this->redirectToRoute('admin_news_index');
            }
            if (isset($seenIds[$rawId])) {
                continue;
            }

            $seenIds[$rawId] = true;
            $ids[] = $rawId;
            if (count($ids) > 50) {
                $this->addFlash('error', 'Pro Sammelaktion können höchstens 50 Einträge bearbeitet werden.');

                return $this->redirectToRoute('admin_news_index');
            }
        }

        if ([] === $ids) {
            $this->addFlash('error', 'Wähle mindestens einen Eintrag aus.');

            return $this->redirectToRoute('admin_news_index');
        }

        $action = self::input($request->request, 'action');
        if (!in_array($action, ['publish', 'draft', 'feature', 'unfeature', 'delete'], true)) {
            $this->addFlash('error', 'Bitte wähle eine gültige Sammelaktion aus.');

            return $this->redirectToRoute('admin_news_index');
        }
        if ('delete' === $action && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException();
        }
        if ('delete' === $action && '1' !== self::input($request->request, 'confirm_delete')) {
            $this->addFlash('error', 'Bestätige das Löschen der ausgewählten Inhalte.');

            return $this->redirectToRoute('admin_news_index');
        }

        $editor = $this->getUser();
        if (!$editor instanceof AdminUser) {
            throw $this->createAccessDeniedException();
        }

        $itemsFound = $entityManager->getConnection()->transactional(function () use ($ids, $articles, $action, $comments, $entityManager, $revisions, $articleRevisions, $deletionJobs, $inlineImages, $editor): bool {
            $items = $articles->findByIdsForUpdate($ids);
            if (count($items) !== count($ids)) {
                return false;
            }

            if ('delete' === $action) {
                $objectIds = $articleRevisions->findThumbnailObjectIdsForArticles($ids);
                $objectIds = array_merge($objectIds, $inlineImages->findIdsForNewsArticles($ids));
                foreach ($items as $item) {
                    $thumbnailId = $item->getThumbnailObjectId();
                    if (null !== $thumbnailId) {
                        $objectIds[] = $thumbnailId;
                    }
                }
                self::queuePrivateObjectDeletions($objectIds, $entityManager, $deletionJobs);
            }

            foreach ($items as $item) {
                $entityManager->refresh($item, LockMode::PESSIMISTIC_WRITE);
                if ('delete' === $action) {
                    $comments->deleteForContent(CommentContentType::News, $item->getId());
                    $entityManager->remove($item);

                    continue;
                }
                if ('publish' === $action) {
                    $item->setPublishingStatus(PublishingStatus::Published);
                    $item->setPublicationAt(null);
                } elseif ('draft' === $action) {
                    $item->setPublishingStatus(PublishingStatus::Draft);
                } elseif ('feature' === $action) {
                    $item->setFeatured(true);
                } elseif ('unfeature' === $action) {
                    $item->setFeatured(false);
                }

                $revisions->recordCurrent($item, $editor);
            }

            $entityManager->flush();

            return true;
        });
        if (true !== $itemsFound) {
            $this->addFlash('error', 'Mindestens ein ausgewählter Eintrag wurde nicht gefunden.');

            return $this->redirectToRoute('admin_news_index');
        }

        $actionLabelKey = match ($action) {
            'publish' => 'veröffentlicht',
            'draft' => 'als Entwurf gespeichert',
            'feature' => 'hervorgehoben',
            'unfeature' => 'nicht mehr hervorgehoben',
            default => 'gelöscht',
        };
        $this->addFlash('success', $translator->trans('%count% Einträge wurden %action%.', [
            '%count%' => count($ids),
            '%action%' => $translator->trans($actionLabelKey, [], 'messages'),
        ], 'messages'));

        return $this->redirectToRoute('admin_news_index');
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        NewsArticleRepository $articles,
        ContentCategoryRepository $categories,
        ContentTagManager $tagManager,
        EntityManagerInterface $entityManager,
        SiteSettingsProvider $siteSettings,
        NewsArticleRevisionManager $revisions,
        RichTextSanitizer $richText,
        ContentInlineImageRepository $inlineImages,
        ContentInlineImageManager $inlineImageManager,
    ): Response {
        return $this->editForm($request, null, $articles, $categories, $tagManager, $entityManager, $siteSettings, $revisions, $richText, $inlineImages, $inlineImageManager);
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
        NewsArticleRepository $articles,
        ContentCategoryRepository $categories,
        ContentTagManager $tagManager,
        EntityManagerInterface $entityManager,
        SiteSettingsProvider $siteSettings,
        NewsArticleRevisionManager $revisions,
        RichTextSanitizer $richText,
        ContentInlineImageRepository $inlineImages,
        ContentInlineImageManager $inlineImageManager,
    ): Response {
        $article = $articles->find($id);
        if (!$article instanceof NewsArticle) {
            throw $this->createNotFoundException('News-Beitrag wurde nicht gefunden.');
        }

        return $this->editForm($request, $article, $articles, $categories, $tagManager, $entityManager, $siteSettings, $revisions, $richText, $inlineImages, $inlineImageManager);
    }

    #[Route(
        '/{id}/history',
        name: 'history',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['GET'],
    )]
    public function history(
        string $id,
        Request $request,
        NewsArticleRepository $articles,
        NewsArticleRevisionRepository $revisions,
    ): Response {
        $article = $articles->find($id);
        if (!$article instanceof NewsArticle) {
            throw $this->createNotFoundException('News-Beitrag wurde nicht gefunden.');
        }

        $requestedPage = self::input($request->query, 'page');
        $page = ctype_digit($requestedPage) ? (int) $requestedPage : 1;
        $page = max(1, min(100000, $page));
        $total = $revisions->countForArticle($article);
        $pageCount = max(1, (int) ceil($total / self::REVISION_PAGE_SIZE));
        $page = min($page, $pageCount);
        $response = $this->render('admin/news/history.html.twig', [
            'article' => $article,
            'revisions' => $revisions->findForArticle($article, self::REVISION_PAGE_SIZE, ($page - 1) * self::REVISION_PAGE_SIZE),
            'page' => $page,
            'page_count' => $pageCount,
            'total' => $total,
        ]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function editForm(
        Request $request,
        ?NewsArticle $article,
        NewsArticleRepository $articles,
        ContentCategoryRepository $categories,
        ContentTagManager $tagManager,
        EntityManagerInterface $entityManager,
        SiteSettingsProvider $siteSettings,
        NewsArticleRevisionManager $revisions,
        RichTextSanitizer $richText,
        ContentInlineImageRepository $inlineImages,
        ContentInlineImageManager $inlineImageManager,
    ): Response {
        $siteTimezone = new \DateTimeZone($siteSettings->current()->getTimezone());
        $existingPublicationAt = $article?->getPublishedAt();
        $values = [
            'title' => $article?->getTitle() ?? '',
            'slug' => $article?->getSlug() ?? '',
            'category' => $article?->getCategory() ?? '',
            'tags' => $article?->getTagNames() ?? '',
            'summary' => $article?->getSummary() ?? '',
            'body' => $article?->getBody() ?? '',
            'author_name' => $article?->getAuthorName() ?? 'Redaktion',
            'status' => $article?->getStatus()->value ?? PublishingStatus::Draft->value,
            'publish_at' => $existingPublicationAt?->setTimezone($siteTimezone)->format('Y-m-d\\TH:i') ?? '',
            'featured' => $article?->isFeatured() ?? false,
            'comments_enabled' => $article?->isCommentsEnabled() ?? $siteSettings->current()->isDefaultCommentsEnabled(),
            'members_only' => $article?->isMembersOnly() ?? false,
            'custom_fields' => $article?->getCustomFields() ?? [],
        ];
        $errors = [];
        $tagNames = [];
        $inlineImageIds = [];
        $thumbnailUpload = null;
        $removeThumbnail = false;

        if ($request->isMethod('POST')) {
            $token = self::input($request->request, '_token');
            if ('' === $token || !$this->isCsrfTokenValid('admin_news_save', $token)) {
                throw $this->createAccessDeniedException();
            }

            $payload = $request->request->all();
            $allowedFields = [
                '_token', 'title', 'slug', 'author_name', 'category', 'tags', 'summary', 'body', 'status',
                'publish_at', 'featured', 'comments_enabled', 'members_only', 'remove_thumbnail',
                'custom_fields_present', 'custom_fields',
            ];
            if ([] !== array_diff(array_keys($payload), $allowedFields)) {
                $errors[] = 'Die Anfrage enthält unerwartete Eingabefelder.';
            }
            foreach (['_token', 'title', 'slug', 'author_name', 'category', 'tags', 'summary', 'body', 'status', 'publish_at'] as $field) {
                if (isset($payload[$field]) && !is_string($payload[$field])) {
                    $errors[] = 'Die Eingabe enthält einen ungültigen Wert.';
                }
            }
            foreach (['featured', 'comments_enabled', 'members_only', 'remove_thumbnail'] as $field) {
                if (isset($payload[$field]) && '1' !== $payload[$field]) {
                    $errors[] = 'Die Eingabe enthält einen ungültigen Wert.';
                }
            }
            if (array_key_exists('custom_fields', $payload) && !array_key_exists('custom_fields_present', $payload)) {
                $errors[] = 'Die benutzerdefinierten Felder benötigen eine gültige Formularmarkierung.';
            }
            if (array_key_exists('custom_fields_present', $payload) && '1' !== $payload['custom_fields_present']) {
                $errors[] = 'Die benutzerdefinierten Felder benötigen eine gültige Formularmarkierung.';
            }
            $customFields = $article?->getCustomFields() ?? [];
            if ('1' === ($payload['custom_fields_present'] ?? null)) {
                $normalizedCustomFields = CustomFieldSet::normalize($payload['custom_fields'] ?? []);
                if (null === $normalizedCustomFields) {
                    $errors[] = 'Die benutzerdefinierten News-Felder sind ungültig oder überschreiten die zulässigen Grenzen.';
                } else {
                    $customFields = $normalizedCustomFields;
                }
            }

            $uploadedFields = array_keys($request->files->all());
            if ([] !== array_diff($uploadedFields, ['thumbnail'])) {
                $errors[] = 'Die Anfrage enthält unerwartete Uploadfelder.';
            }
            $rawThumbnailUpload = $request->files->get('thumbnail');
            if (null !== $rawThumbnailUpload && !($rawThumbnailUpload instanceof UploadedFile)) {
                $errors[] = 'Bitte wähle eine einzelne gültige Bilddatei aus.';
            } elseif ($rawThumbnailUpload instanceof UploadedFile) {
                $thumbnailUpload = $rawThumbnailUpload;
            }
            $removeThumbnail = $request->request->has('remove_thumbnail');
            if ($removeThumbnail && null !== $thumbnailUpload) {
                $errors[] = 'Lade ein Bild hoch oder entferne das gespeicherte Bild, aber nicht beides gleichzeitig.';
            }
            if ($removeThumbnail && null === $article?->getThumbnailObjectId()) {
                $errors[] = 'Für diesen Beitrag ist kein Vorschaubild gespeichert.';
            }

            $values = [
                'title' => trim(self::input($request->request, 'title')),
                'slug' => trim(self::input($request->request, 'slug')),
                'category' => trim(self::input($request->request, 'category')),
                'tags' => self::input($request->request, 'tags'),
                'summary' => trim(self::input($request->request, 'summary')),
                'body' => self::input($request->request, 'body'),
                'author_name' => trim(self::input($request->request, 'author_name')),
                'status' => self::input($request->request, 'status'),
                'publish_at' => trim(self::input($request->request, 'publish_at')),
                'featured' => $request->request->has('featured'),
                'comments_enabled' => $request->request->has('comments_enabled'),
                'members_only' => $request->request->has('members_only'),
                'custom_fields' => $customFields,
            ];

            try {
                $values['body'] = $richText->sanitize($values['body']);
                $inlineImageIds = $richText->inlineImageIds($values['body']);
            } catch (\InvalidArgumentException) {
                $values['body'] = '';
                $errors[] = 'Der Inhalt ist zu groß oder enthält ungültiges UTF-8.';
            }
            if (count($inlineImageIds) > 20) {
                $errors[] = 'Pro News-Beitrag sind höchstens 20 Inline-Bilder zulässig.';
            } elseif ([] !== $inlineImageIds
                && (null === $article || !$inlineImages->allBelongToNewsArticle($article->getId(), $inlineImageIds))
            ) {
                $errors[] = 'Der Inhalt enthält ein Bild, das nicht zu diesem News-Beitrag gehört.';
            }

            $status = PublishingStatus::tryFrom($values['status']);
            $publicationAt = null;
            if ('' !== $values['publish_at']) {
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
            try {
                $tagNames = ContentTagManager::parseNames($values['tags']);
            } catch (\InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
            if ('' === $values['title'] || mb_strlen($values['title']) > 180) {
                $errors[] = 'Der Titel ist erforderlich und darf höchstens 180 Zeichen lang sein.';
            }
            if (mb_strlen($values['category']) > 100) {
                $errors[] = 'Die Kategorie darf höchstens 100 Zeichen lang sein.';
            }
            if ('' !== $values['category']
                && null === $categories->findForTypeAndName(ContentCategoryType::News, $values['category'])
            ) {
                $errors[] = 'Bitte wähle eine vorhandene News-Kategorie.';
            }
            if (mb_strlen($values['summary']) > 500) {
                $errors[] = 'Der Vorspann darf höchstens 500 Zeichen lang sein.';
            }
            $plainBody = trim(html_entity_decode(strip_tags($values['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (('' === $plainBody && [] === $inlineImageIds) || mb_strlen($values['body']) > 100000) {
                $errors[] = 'Der Inhalt ist erforderlich und darf höchstens 100.000 Zeichen lang sein.';
            }
            if (false === preg_match('//u', $values['author_name'])
                || '' === $values['author_name']
                || mb_strlen($values['author_name'], 'UTF-8') > 120
                || 1 === preg_match('/\p{Cc}/u', $values['author_name'])
            ) {
                $errors[] = 'Die Autorenangabe ist erforderlich und darf höchstens 120 Zeichen lang sein.';
            }
            if (null === $status) {
                $errors[] = 'Bitte wähle einen gültigen Veröffentlichungsstatus.';
            }

            $slug = '' === $values['slug'] ? self::slugify($values['title']) : $values['slug'];
            if (mb_strlen($slug) > 200 || 1 !== preg_match('/\\A[a-z0-9]+(?:-[a-z0-9]+)*\\z/', $slug)) {
                $errors[] = 'Der Permalink darf nur Kleinbuchstaben, Ziffern und einzelne Bindestriche enthalten.';
            }
            if (null !== ($existing = $articles->findOneBySlug($slug))
                && (null === $article || $existing->getId() !== $article->getId())
            ) {
                $errors[] = 'Für diesen Permalink gibt es bereits einen Beitrag.';
            }

            $storedThumbnail = null;
            if ([] === $errors && $thumbnailUpload instanceof UploadedFile) {
                try {
                    $storedThumbnail = $this->thumbnailUploads->store($thumbnailUpload);
                } catch (\InvalidArgumentException $exception) {
                    $errors[] = $exception->getMessage();
                } catch (PrivateStorageOperationFailed) {
                    $errors[] = 'Der sichere private Dateispeicher ist derzeit nicht verfügbar; der Upload wurde gesperrt.';
                }
            }

            if ([] === $errors) {
                $editor = $this->getUser();
                if (!$editor instanceof AdminUser) {
                    if (null !== $storedThumbnail) {
                        self::cleanupUploadedThumbnail($storedThumbnail['object_id'], $this->thumbnailUploads, $this->deletionJobs, $entityManager, $this->logger);
                    }

                    throw $this->createAccessDeniedException();
                }

                try {
                    $entityManager->getConnection()->transactional(function () use ($entityManager, &$article, $articles, $tagManager, $tagNames, $values, $slug, $status, $publicationAt, $revisions, $editor, $storedThumbnail, $removeThumbnail, $inlineImageIds, $inlineImageManager): void {
                        if (null !== $article) {
                            $lockedArticle = $articles->findByIdForUpdate($article->getId());
                            if (!$lockedArticle instanceof NewsArticle) {
                                throw $this->createNotFoundException('News-Beitrag wurde zwischenzeitlich entfernt.');
                            }
                            $article = $lockedArticle;
                            $entityManager->refresh($article, LockMode::PESSIMISTIC_WRITE);
                        }

                        if (null === $article) {
                            $article = new NewsArticle(
                                $values['title'],
                                $slug,
                                '' === $values['category'] ? null : $values['category'],
                                '' === $values['summary'] ? null : $values['summary'],
                                $values['body'],
                                $status,
                                $values['featured'],
                                $values['comments_enabled'],
                                $values['author_name'],
                                $values['members_only'],
                            );
                        } else {
                            $article->revise(
                                $values['title'],
                                $slug,
                                '' === $values['category'] ? null : $values['category'],
                                '' === $values['summary'] ? null : $values['summary'],
                                $values['body'],
                                $status,
                                $values['featured'],
                                $values['comments_enabled'],
                                $values['author_name'],
                                $values['members_only'],
                            );
                        }

                        $article->replaceCustomFields($values['custom_fields']);
                        if (null !== $storedThumbnail) {
                            $article->attachThumbnail($storedThumbnail['object_id'], $storedThumbnail['mime_type'], $storedThumbnail['size']);
                        } elseif ($removeThumbnail) {
                            $article->removeThumbnail();
                        }

                        $article->setPublicationAt($publicationAt);
                        $article->replaceTags($tagManager->resolve($tagNames));
                        $entityManager->persist($article);
                        $entityManager->flush();
                        $revisions->recordCurrent($article, $editor);
                        $entityManager->flush();
                        $inlineImageManager->removeUnusedNewsImages($article, $inlineImageIds);
                        $entityManager->flush();
                    });
                } catch (\Throwable $exception) {
                    if (null !== $storedThumbnail) {
                        self::cleanupUploadedThumbnail($storedThumbnail['object_id'], $this->thumbnailUploads, $this->deletionJobs, $entityManager, $this->logger);
                    }

                    throw $exception;
                }
                $this->addFlash('success', 'News-Beitrag wurde gespeichert.');

                return $this->redirectToRoute('admin_news_index');
            }
        }

        return $this->render('admin/news/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'categories' => $categories->findForAdmin(ContentCategoryType::News),
            'is_edit' => null !== $article,
            'article_id' => $article?->getId(),
            'image_upload_url' => null === $article ? null : $this->generateUrl('admin_content_inline_image_upload', ['type' => 'news', 'ownerId' => $article->getId()]),
            'has_thumbnail' => null !== $article?->getThumbnailObjectId(),
            'site_timezone' => $siteTimezone->getName(),
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /** @param list<string> $objectIds */
    private static function queuePrivateObjectDeletions(
        array $objectIds,
        EntityManagerInterface $entityManager,
        PrivateObjectDeletionJobRepository $jobs,
    ): void {
        foreach (array_unique($objectIds) as $objectId) {
            if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)) {
                throw new \LogicException('Die Kennung eines privaten News-Bildobjekts ist ungültig.');
            }
            if (!$jobs->hasObject($objectId)) {
                $entityManager->persist(new PrivateObjectDeletionJob($objectId));
            }
        }
    }

    private static function cleanupUploadedThumbnail(
        string $objectId,
        VideoThumbnailUploadService $uploads,
        PrivateObjectDeletionJobRepository $jobs,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
    ): void {
        try {
            $uploads->delete($objectId);
        } catch (PrivateStorageOperationFailed|\InvalidArgumentException $cleanupError) {
            try {
                self::queuePrivateObjectDeletions([$objectId], $entityManager, $jobs);
                $entityManager->flush();
            } catch (\Throwable $queueError) {
                $logger->critical('Ein nicht zugeordneter News-Bildupload konnte nicht bereinigt oder zur Bereinigung vorgemerkt werden.', [
                    'cleanup_error' => $cleanupError::class,
                    'queue_error' => $queueError::class,
                ]);
            }
        }
    }

    /**
     * @template TInput of string|int|float|bool|null
     *
     * @param InputBag<TInput> $bag
     */
    private static function input(InputBag $bag, string $key): string
    {
        $value = $bag->get($key);

        return is_string($value) ? $value : '';
    }

    private static function slugify(string $value): string
    {
        $slug = new AsciiSlugger('en')->slug($value)->lower()->toString();

        return '' === $slug ? bin2hex(random_bytes(12)) : mb_substr($slug, 0, 200);
    }
}
