<?php

declare(strict_types=1);

namespace App\Module\Comment\Controller;

use App\Entity\CommentContentType;
use App\Entity\CommentProvider;
use App\Entity\CommentStatus;
use App\Entity\ContentComment;
use App\Entity\ContentRatingValue;
use App\Entity\MemberAccount;
use App\Entity\NewsArticle;
use App\Entity\Video;
use App\Module\Comment\CommentPagination;
use App\Module\News\Service\NewsArticleAccessPolicy;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\ContentCommentRepository;
use App\Repository\ContentRatingRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\VideoRepository;
use App\Service\SiteSettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/bewertungen', name: 'public_rating_')]
#[IsGranted('ROLE_MEMBER')]
final class PublicContentRatingController extends AbstractController
{
    #[Route('/videos/{id}', name: 'video', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function video(
        string $id,
        Request $request,
        VideoRepository $videos,
        ContentRatingRepository $ratings,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.content_rating')] RateLimiterFactoryInterface $limiter,
        RateLimitKeyFactory $rateLimitKeys,
    ): Response {
        $video = $videos->findPublishedById($id);
        if (!$video instanceof Video) {
            return $this->notFound();
        }

        $member = $this->currentMember();
        $value = $this->validatedValue($request, 'content_rating_video_'.$video->getId());
        if ($value instanceof Response) {
            return $this->noStore($value);
        }

        $limitResponse = $this->consumeLimit($limiter, $member, $rateLimitKeys, $logger);
        if (null !== $limitResponse) {
            return $this->noStore($limitResponse);
        }

        if (null === $value) {
            $ratings->withdrawFromVideo($member, $video);
        } else {
            $ratings->setForVideo($member, $video, $value);
        }

        return $this->noStore($this->redirectToRoute('video_show', [
            'id' => $video->getId(),
            '_fragment' => 'video-rating',
        ]));
    }

    #[Route('/kommentare/{id}', name: 'comment', requirements: ['id' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function comment(
        string $id,
        Request $request,
        ContentCommentRepository $comments,
        ContentRatingRepository $ratings,
        NewsArticleRepository $articles,
        VideoRepository $videos,
        SiteSettingsProvider $settings,
        NewsArticleAccessPolicy $articleAccess,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.content_rating')] RateLimiterFactoryInterface $limiter,
        RateLimitKeyFactory $rateLimitKeys,
    ): Response {
        $comment = $comments->find($id);
        if (!$comment instanceof ContentComment || CommentStatus::Approved !== $comment->getStatus()) {
            return $this->notFound();
        }

        $member = $this->currentMember();
        if (CommentProvider::Native !== $settings->current()->getCommentProvider()) {
            return $this->notFound();
        }

        $target = match ($comment->getContentType()) {
            CommentContentType::News => $articles->findPublishedById($comment->getContentId()),
            CommentContentType::Video => $videos->findPublishedById($comment->getContentId()),
        };
        if ((!$target instanceof NewsArticle && !$target instanceof Video) || !$target->isCommentsEnabled()) {
            return $this->notFound();
        }
        if ($target instanceof NewsArticle && !$articleAccess->canRead($target, $this->getUser())) {
            return $this->notFound();
        }

        $value = $this->validatedValue($request, 'content_rating_comment_'.$comment->getId());
        if ($value instanceof Response) {
            return $this->noStore($value);
        }

        $limitResponse = $this->consumeLimit($limiter, $member, $rateLimitKeys, $logger);
        if (null !== $limitResponse) {
            return $this->noStore($limitResponse);
        }

        if (null === $value) {
            $ratings->withdrawFromComment($member, $comment);
        } else {
            $ratings->setForComment($member, $comment, $value);
        }

        $route = $target instanceof NewsArticle ? 'news_show' : 'video_show';
        $parameters = $target instanceof NewsArticle
            ? ['slug' => $target->getSlug()]
            : ['id' => $target->getId()];
        $parameters['_fragment'] = 'comments';
        $commentPage = CommentPagination::pageParameter($request);
        if (null !== $commentPage) {
            $parameters['comment_page'] = $commentPage;
        }

        return $this->noStore($this->redirectToRoute($route, $parameters));
    }

    private function currentMember(): MemberAccount
    {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount) {
            throw $this->createAccessDeniedException();
        }

        return $member;
    }

    private function validatedValue(Request $request, string $csrfId): ContentRatingValue|Response|null
    {
        $values = $request->request->all();
        $rawValue = $values['value'] ?? null;
        if (!is_string($rawValue)
            || !$this->isCsrfTokenValid($csrfId, is_string($values['_token'] ?? null) ? $values['_token'] : '')
        ) {
            return new Response('', Response::HTTP_FORBIDDEN, ['Cache-Control' => 'private, no-store']);
        }

        if ('none' === $rawValue) {
            return null;
        }

        $value = ContentRatingValue::tryFrom($rawValue);
        if (null === $value) {
            return new Response('', Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'private, no-store']);
        }

        return $value;
    }

    private function consumeLimit(
        RateLimiterFactoryInterface $limiter,
        MemberAccount $member,
        RateLimitKeyFactory $rateLimitKeys,
        LoggerInterface $logger,
    ): ?Response {
        $key = $rateLimitKeys->forIdentity($member->getId(), 'content-rating');
        try {
            $limit = $limiter->create($key)->consume();
        } catch (\Exception) {
            $logger->warning('A content rating was blocked because its abuse quota could not be checked.');

            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Cache-Control' => 'private, no-store',
                'Retry-After' => '60',
            ]);
        }
        if ($limit->isAccepted()) {
            return null;
        }

        $retrySeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        return new Response('', Response::HTTP_TOO_MANY_REQUESTS, [
            'Cache-Control' => 'private, no-store',
            'Retry-After' => (string) $retrySeconds,
        ]);
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function notFound(): Response
    {
        return $this->noStore($this->render('public/not_found.html.twig', [
            'content_type' => 'Bewertung',
        ], new Response(status: Response::HTTP_NOT_FOUND)));
    }
}
