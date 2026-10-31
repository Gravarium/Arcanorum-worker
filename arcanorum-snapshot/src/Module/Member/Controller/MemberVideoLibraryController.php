<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\ContentRating;
use App\Entity\MemberAccount;
use App\Entity\MemberVideoCollection;
use App\Entity\Video;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\ContentRatingRepository;
use App\Repository\MemberSavedVideoRepository;
use App\Repository\MemberVideoHistoryRepository;
use App\Repository\VideoRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MEMBER')]
#[Route('/konto/bibliothek', name: 'member_video_library_')]
final class MemberVideoLibraryController extends AbstractController
{
    private const int PAGE_SIZE = 25;
    private const int MAX_PAGE = 5000;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        MemberSavedVideoRepository $savedVideos,
        MemberVideoHistoryRepository $history,
        ContentRatingRepository $ratings,
    ): Response {
        $member = $this->currentMember();
        $query = $request->query->all();
        foreach (array_keys($query) as $key) {
            if (!in_array($key, ['view', 'page'], true)) {
                return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
            }
        }

        $view = $query['view'] ?? 'watch-later';
        $rawPage = $query['page'] ?? '1';
        if (!is_string($view) || !in_array($view, ['watch-later', 'favorites', 'rated', 'history'], true)
            || !is_string($rawPage) || !ctype_digit($rawPage) || strlen($rawPage) > 4
            || (int) $rawPage < 1 || (int) $rawPage > self::MAX_PAGE
        ) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $page = (int) $rawPage;
        $offset = ($page - 1) * self::PAGE_SIZE;
        $items = match ($view) {
            'favorites' => $savedVideos->findVisibleForMember($member, MemberVideoCollection::Favorite, self::PAGE_SIZE + 1, $offset),
            'rated' => $ratings->findPublishedVideoRatingsForMember($member, self::PAGE_SIZE + 1, $offset),
            'history' => $history->findVisibleForMember($member, self::PAGE_SIZE + 1, $offset),
            default => $savedVideos->findVisibleForMember($member, MemberVideoCollection::WatchLater, self::PAGE_SIZE + 1, $offset),
        };
        $response = $this->render('member/video_library.html.twig', [
            'member' => $member,
            'view' => $view,
            'items' => array_slice($items, 0, self::PAGE_SIZE),
            'page' => $page,
            'has_next' => count($items) > self::PAGE_SIZE,
        ]);

        return $this->noStore($response);
    }

    #[Route('/{collection}/{action}/{videoId}', name: 'change', requirements: [
        'collection' => 'watch-later|favorites',
        'action' => 'add|remove',
        'videoId' => '[a-f0-9]{32}',
    ], methods: ['POST'])]
    public function change(
        string $collection,
        string $action,
        string $videoId,
        Request $request,
        MemberSavedVideoRepository $savedVideos,
        VideoRepository $videos,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.member_video_library_account')] RateLimiterFactoryInterface $accountLimiter,
        #[Autowire(service: 'limiter.member_video_library_ip')] RateLimiterFactoryInterface $ipLimiter,
        RateLimitKeyFactory $rateLimitKeys,
    ): Response {
        $member = $this->currentMember();
        $values = $request->request->all();
        if (array_keys($values) !== ['_token'] || !is_string($values['_token'] ?? null)) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }
        if (!$this->isCsrfTokenValid('member_video_library_'.$collection.'_'.$action.'_'.$videoId, $values['_token'])) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }
        $limitResponse = $this->consumeLimits($request, $member, $accountLimiter, $ipLimiter, $rateLimitKeys, $logger);
        if (null !== $limitResponse) {
            return $this->noStore($limitResponse);
        }

        $type = 'watch-later' === $collection ? MemberVideoCollection::WatchLater : MemberVideoCollection::Favorite;
        if ('add' === $action) {
            if (!$videos->findPublishedById($videoId) instanceof Video) {
                return $this->noStore(new Response('', Response::HTTP_NOT_FOUND));
            }
            try {
                $savedVideos->add($member, $videoId, $type);
            } catch (\Throwable $exception) {
                $logger->error('Eine private Mitgliedsvideoliste konnte nicht geändert werden.', ['failure_type' => $exception::class]);

                return $this->noStore(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
            }
        } else {
            try {
                $savedVideos->remove($member, $videoId, $type);
            } catch (\Throwable $exception) {
                $logger->error('Eine private Mitgliedsvideoliste konnte nicht geändert werden.', ['failure_type' => $exception::class]);

                return $this->noStore(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
            }
        }

        return $this->noStore($this->redirectToRoute('video_show', ['id' => $videoId], Response::HTTP_SEE_OTHER));
    }

    #[Route('/{view}/{videoId}/entfernen', name: 'remove', requirements: [
        'view' => 'watch-later|favorites|rated|history',
        'videoId' => '[a-f0-9]{32}',
    ], methods: ['POST'])]
    public function remove(
        string $view,
        string $videoId,
        Request $request,
        MemberSavedVideoRepository $savedVideos,
        MemberVideoHistoryRepository $history,
        ContentRatingRepository $ratings,
        VideoRepository $videos,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.member_video_library_account')] RateLimiterFactoryInterface $accountLimiter,
        #[Autowire(service: 'limiter.member_video_library_ip')] RateLimiterFactoryInterface $ipLimiter,
        RateLimitKeyFactory $rateLimitKeys,
    ): Response {
        $member = $this->currentMember();
        $values = $request->request->all();
        if (array_keys($values) !== ['_token'] || !is_string($values['_token'] ?? null)) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }
        if (!$this->isCsrfTokenValid('member_video_library_remove_'.$view.'_'.$videoId, $values['_token'])) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }
        $limitResponse = $this->consumeLimits($request, $member, $accountLimiter, $ipLimiter, $rateLimitKeys, $logger);
        if (null !== $limitResponse) {
            return $this->noStore($limitResponse);
        }

        try {
            if (in_array($view, ['watch-later', 'favorites'], true)) {
                $type = 'watch-later' === $view ? MemberVideoCollection::WatchLater : MemberVideoCollection::Favorite;
                $savedVideos->remove($member, $videoId, $type);
            } elseif ('history' === $view) {
                $history->remove($member, $videoId);
            } else {
                $video = $videos->find($videoId);
                $rating = $video instanceof Video
                    ? $ratings->findOneBy(['member' => $member, 'video' => $video])
                    : null;
                if (!$rating instanceof ContentRating || null === $rating->getVideo()) {
                    return $this->noStore(new Response('', Response::HTTP_NOT_FOUND));
                }
                $ratings->withdrawFromVideo($member, $rating->getVideo());
            }
        } catch (\Throwable $exception) {
            $logger->error('Eine private Mitgliedsvideoliste konnte nicht geändert werden.', ['failure_type' => $exception::class]);

            return $this->noStore(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
        }

        return $this->noStore($this->redirectToRoute('member_video_library_index', ['view' => $view], Response::HTTP_SEE_OTHER));
    }

    #[Route('/history/leeren', name: 'clear_history', methods: ['POST'])]
    public function clearHistory(
        Request $request,
        MemberVideoHistoryRepository $history,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.member_video_library_account')] RateLimiterFactoryInterface $accountLimiter,
        #[Autowire(service: 'limiter.member_video_library_ip')] RateLimiterFactoryInterface $ipLimiter,
        RateLimitKeyFactory $rateLimitKeys,
    ): Response {
        $member = $this->currentMember();
        $values = $request->request->all();
        if (array_keys($values) !== ['_token'] || !is_string($values['_token'] ?? null)) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }
        if (!$this->isCsrfTokenValid('member_video_library_clear_history', $values['_token'])) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }
        $limitResponse = $this->consumeLimits($request, $member, $accountLimiter, $ipLimiter, $rateLimitKeys, $logger);
        if (null !== $limitResponse) {
            return $this->noStore($limitResponse);
        }

        try {
            $history->clear($member);
        } catch (\Throwable $exception) {
            $logger->error('Der private Videoverlauf konnte nicht geleert werden.', ['failure_type' => $exception::class]);

            return $this->noStore(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
        }

        return $this->noStore($this->redirectToRoute('member_video_library_index', ['view' => 'history'], Response::HTTP_SEE_OTHER));
    }

    private function currentMember(): MemberAccount
    {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount || !$member->isActive() || !$member->isEmailVerified()) {
            throw $this->createAccessDeniedException();
        }

        return $member;
    }

    private function consumeLimits(
        Request $request,
        MemberAccount $member,
        RateLimiterFactoryInterface $accountLimiter,
        RateLimiterFactoryInterface $ipLimiter,
        RateLimitKeyFactory $rateLimitKeys,
        LoggerInterface $logger,
    ): ?Response {
        $clientIp = $request->getClientIp();
        if (null === $clientIp) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            $accountKey = $rateLimitKeys->forIdentity($member->getId(), 'member-video-library-account');
            $ipKey = $rateLimitKeys->forIp($request, 'member-video-library-ip');
            $accountLimit = $accountLimiter->create($accountKey)->consume();
            $ipLimit = $ipLimiter->create($ipKey)->consume();
        } catch (\Throwable $exception) {
            $logger->error('Eine Mitgliedsmedienliste wurde wegen eines Drosselungsfehlers geschlossen.', [
                'failure_type' => $exception::class,
            ]);

            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $accountLimit->isAccepted() && $ipLimit->isAccepted()
            ? null
            : new Response('', Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
