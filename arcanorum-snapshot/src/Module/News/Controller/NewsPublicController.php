<?php

declare(strict_types=1);

namespace App\Module\News\Controller;

use App\Entity\CommentContentType;
use App\Entity\CommentProvider;
use App\Entity\ContentCategoryType;
use App\Entity\ContentComment;
use App\Entity\MemberAccount;
use App\Entity\NewsArticle;
use App\Module\Comment\CommentPagination;
use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use App\Module\News\Service\NewsArticleAccessPolicy;
use App\Repository\ContentCategoryRepository;
use App\Repository\ContentCommentRepository;
use App\Repository\ContentRatingRepository;
use App\Repository\NewsArticleRepository;
use App\Service\PublicShareMetadataFactory;
use App\Service\SiteSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/news', name: 'news_')]
final class NewsPublicController extends AbstractController
{
    private const int PAGE_SIZE = 24;

    #[Route('', name: 'index', methods: ['GET'])]
    #[Route(
        '/category/{slug}',
        name: 'category',
        requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['GET'],
    )]
    public function index(
        Request $request,
        NewsArticleRepository $articles,
        ContentCategoryRepository $categories,
        NewsArticleAccessPolicy $articleAccess,
        CanonicalPublicUrlGenerator $publicUrls,
        ?string $slug = null,
    ): Response {
        $categoryPage = null;
        if (null !== $slug) {
            $categoryPage = $categories->findForTypeAndSlug(ContentCategoryType::News, $slug);
            if (null === $categoryPage) {
                return $this->render('public/not_found.html.twig', [
                    'content_type' => 'News-Kategorie',
                ], new Response(status: Response::HTTP_NOT_FOUND));
            }
        }

        $search = mb_substr(trim($request->query->getString('q')), 0, 100);
        $categoryName = $categoryPage?->getName();
        $category = $categoryName ?? mb_substr(trim($request->query->getString('category')), 0, 100);
        $categoryCanonicalUrl = null !== $categoryPage && $publicUrls->isConfigured()
            ? $publicUrls->generate('news_category', ['slug' => $categoryPage->getSlug()])
            : null;
        $tag = mb_substr(trim($request->query->getString('tag')), 0, 100);
        $page = self::pageNumber($request);
        $items = $articles->findPublished(
            search: $search,
            limit: self::PAGE_SIZE + 1,
            offset: ($page - 1) * self::PAGE_SIZE,
            category: '' === $category ? null : $category,
            tag: '' === $tag ? null : $tag,
            includeMemberOnlyBodyMatches: $articleAccess->canReadMembersOnly($this->getUser()),
        );

        return $this->render('news/index.html.twig', [
            'articles' => array_slice($items, 0, self::PAGE_SIZE),
            'search' => $search,
            'category' => $category,
            'category_page' => $categoryPage,
            'child_categories' => null !== $categoryPage ? $categories->findNewsChildren($categoryPage) : [],
            'category_canonical_url' => $categoryCanonicalUrl,
            'tag' => $tag,
            'categories' => $categories->findForAdmin(ContentCategoryType::News),
            'page' => $page,
            'has_next' => count($items) > self::PAGE_SIZE,
        ]);
    }

    #[Route(
        '/{slug}',
        name: 'show',
        requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['GET'],
    )]
    public function show(
        string $slug,
        Request $request,
        NewsArticleRepository $articles,
        ContentCommentRepository $comments,
        ContentRatingRepository $ratings,
        SiteSettingsProvider $siteSettings,
        NewsArticleAccessPolicy $articleAccess,
        PublicShareMetadataFactory $shareMetadata,
    ): Response {
        $article = $articles->findPublishedBySlug($slug);
        if (!$article instanceof NewsArticle) {
            return $this->render('public/not_found.html.twig', [
                'content_type' => 'News-Beitrag',
            ], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $member = $this->getUser();
        $settings = $siteSettings->current();
        $canReadArticle = $articleAccess->canRead($article, $member);
        $memberId = $member instanceof MemberAccount ? $member->getId() : null;
        $commentsAvailable = $canReadArticle && CommentProvider::Native === $settings->getCommentProvider();
        $commentCount = $commentsAvailable
            ? $comments->countApprovedForContent(CommentContentType::News, $article->getId())
            : 0;
        $commentsPerPage = $settings->getCommentsPerPage();
        $commentPageCount = CommentPagination::pageCount($commentCount, $commentsPerPage);
        $commentPage = CommentPagination::selectedPage($request, $commentPageCount);
        $approvedComments = $commentsAvailable
            ? $comments->findApprovedForContent(
                CommentContentType::News,
                $article->getId(),
                $settings->getCommentDefaultSort(),
                $commentsPerPage,
                ($commentPage - 1) * $commentsPerPage,
            )
            : [];
        $commentRatingSummaries = $ratings->summariesForComments(
            array_map(static fn (ContentComment $comment): string => $comment->getId(), $approvedComments),
            $memberId,
        );

        $response = $this->render('news/show.html.twig', [
            'article' => $article,
            'can_read_article' => $canReadArticle,
            'comments' => $approvedComments,
            'comment_page' => $commentPage,
            'comment_page_count' => $commentPageCount,
            'comment_rating_summaries' => $commentRatingSummaries,
            'muted_comment_ids' => self::mutedCommentIds($commentRatingSummaries, $settings->getCommentRatingHideThreshold()),
            'social_metadata' => $shareMetadata->forNewsArticle($article),
            'share_widget_enabled' => $settings->isVideoShareWidgetEnabled(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private static function pageNumber(Request $request): int
    {
        $value = $request->query->getString('page', '1');
        $page = ctype_digit($value) ? (int) $value : 1;

        return max(1, min(5000, $page));
    }

    /**
     * @param array<string, array{like_count: int, dislike_count: int, member_value: ?string}> $summaries
     *
     * @return array<string, true>
     */
    private static function mutedCommentIds(array $summaries, int $threshold): array
    {
        if (0 === $threshold) {
            return [];
        }

        $muted = [];
        foreach ($summaries as $commentId => $summary) {
            if ($summary['dislike_count'] >= $threshold && $summary['dislike_count'] > $summary['like_count']) {
                $muted[$commentId] = true;
            }
        }

        return $muted;
    }
}
