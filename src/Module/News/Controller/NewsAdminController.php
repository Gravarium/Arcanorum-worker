<?php

declare(strict_types=1);

namespace App\Module\News\Controller;

use App\Entity\CommentContentType;
use App\Entity\ContentCategoryType;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Repository\ContentCategoryRepository;
use App\Repository\ContentCommentRepository;
use App\Repository\ContentTagRepository;
use App\Repository\NewsArticleRepository;
use App\Service\ContentTagManager;
use App\Service\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/admin/news', name: 'admin_news_')]
#[IsGranted('ROLE_ADMIN')]
final class NewsAdminController extends AbstractController
{
    private const int ADMIN_PAGE_SIZE = 25;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        NewsArticleRepository $articles,
        ContentCategoryRepository $categories,
        ContentTagRepository $tags,
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
        NewsArticleRepository $articles,
        ContentCommentRepository $comments,
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
        if ('delete' === $action && '1' !== self::input($request->request, 'confirm_delete')) {
            $this->addFlash('error', 'Bestätige das Löschen der ausgewählten Inhalte.');

            return $this->redirectToRoute('admin_news_index');
        }

        $items = $articles->findByIds($ids);
        if (count($items) !== count($ids)) {
            $this->addFlash('error', 'Mindestens ein ausgewählter Eintrag wurde nicht gefunden.');

            return $this->redirectToRoute('admin_news_index');
        }

        $entityManager->getConnection()->transactional(function () use ($items, $action, $comments, $entityManager): void {
            foreach ($items as $item) {
                if ('delete' === $action) {
                    $comments->deleteForContent(CommentContentType::News, $item->getId());
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
                }
            }

            $entityManager->flush();
        });

        $actionLabelKey = match ($action) {
            'publish' => 'veröffentlicht',
            'draft' => 'als Entwurf gespeichert',
            'feature' => 'hervorgehoben',
            'unfeature' => 'nicht mehr hervorgehoben',
            default => 'gelöscht',
        };
        $this->addFlash('success', $translator->trans('%count% Einträge wurden %action%.', [
            '%count%' => count($items),
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
    ): Response {
        return $this->editForm($request, null, $articles, $categories, $tagManager, $entityManager, $siteSettings);
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
    ): Response {
        $article = $articles->find($id);
        if (!$article instanceof NewsArticle) {
            throw $this->createNotFoundException('News-Beitrag wurde nicht gefunden.');
        }

        return $this->editForm($request, $article, $articles, $categories, $tagManager, $entityManager, $siteSettings);
    }

    private function editForm(
        Request $request,
        ?NewsArticle $article,
        NewsArticleRepository $articles,
        ContentCategoryRepository $categories,
        ContentTagManager $tagManager,
        EntityManagerInterface $entityManager,
        SiteSettingsProvider $siteSettings,
    ): Response {
        $values = [
            'title' => $article?->getTitle() ?? '',
            'category' => $article?->getCategory() ?? '',
            'tags' => $article?->getTagNames() ?? '',
            'summary' => $article?->getSummary() ?? '',
            'body' => $article?->getBody() ?? '',
            'status' => $article?->getStatus()->value ?? PublishingStatus::Draft->value,
            'featured' => $article?->isFeatured() ?? false,
            'comments_enabled' => $article?->isCommentsEnabled() ?? $siteSettings->current()->isDefaultCommentsEnabled(),
        ];
        $errors = [];
        $tagNames = [];

        if ($request->isMethod('POST')) {
            $token = self::input($request->request, '_token');
            if ('' === $token || !$this->isCsrfTokenValid('admin_news_save', $token)) {
                throw $this->createAccessDeniedException();
            }

            $values = [
                'title' => trim(self::input($request->request, 'title')),
                'category' => trim(self::input($request->request, 'category')),
                'tags' => self::input($request->request, 'tags'),
                'summary' => trim(self::input($request->request, 'summary')),
                'body' => self::input($request->request, 'body'),
                'status' => self::input($request->request, 'status'),
                'featured' => $request->request->has('featured'),
                'comments_enabled' => $request->request->has('comments_enabled'),
            ];

            $status = PublishingStatus::tryFrom($values['status']);
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
            if ('' === trim($values['body']) || mb_strlen($values['body']) > 100000) {
                $errors[] = 'Der Inhalt ist erforderlich und darf höchstens 100.000 Zeichen lang sein.';
            }
            if (null === $status) {
                $errors[] = 'Bitte wähle einen gültigen Veröffentlichungsstatus.';
            }

            $slug = self::slugify($values['title']);
            if (null !== ($existing = $articles->findOneBySlug($slug))
                && (null === $article || $existing->getId() !== $article->getId())
            ) {
                $errors[] = 'Für diesen Titel gibt es bereits einen Beitrag.';
            }

            if ([] === $errors) {
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
                    );
                }

                $article->replaceTags($tagManager->resolve($tagNames));

                $entityManager->persist($article);
                $entityManager->flush();
                $this->addFlash('success', 'News-Beitrag wurde gespeichert.');

                return $this->redirectToRoute('admin_news_index');
            }
        }

        return $this->render('admin/news/form.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'categories' => $categories->findForAdmin(ContentCategoryType::News),
            'is_edit' => null !== $article,
        ], new Response(status: [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
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
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower(trim($value))) ?? '';
        $slug = trim($slug, '-');

        return '' === $slug ? bin2hex(random_bytes(12)) : mb_substr($slug, 0, 200);
    }
}
