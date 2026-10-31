<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\MemberAccount;
use App\Platform\Security\RateLimitKeyFactory;
use App\Repository\MemberAccountRepository;
use App\Repository\MemberFollowRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MEMBER')]
final class MemberFollowController extends AbstractController
{
    private const int PAGE_SIZE = 20;
    private const int MAX_PAGE = 5000;

    #[Route('/konto/folgen', name: 'member_follow_index', methods: ['GET'])]
    public function index(Request $request, MemberFollowRepository $follows): Response
    {
        $member = $this->currentMember();
        $query = $request->query->all();
        foreach (array_keys($query) as $key) {
            if (!in_array($key, ['view', 'page'], true)) {
                return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
            }
        }

        $view = $query['view'] ?? 'feed';
        $rawPage = $query['page'] ?? '1';
        if (!is_string($view) || !in_array($view, ['feed', 'following', 'followers', 'suggestions'], true)
            || !is_string($rawPage) || !ctype_digit($rawPage) || strlen($rawPage) > 4
            || (int) $rawPage < 1 || (int) $rawPage > self::MAX_PAGE
        ) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $page = (int) $rawPage;
        $offset = ($page - 1) * self::PAGE_SIZE;
        $entries = match ($view) {
            'following' => $follows->findFollowing($member, self::PAGE_SIZE + 1, $offset),
            'followers' => $follows->findFollowers($member, self::PAGE_SIZE + 1, $offset),
            'suggestions' => $follows->findSuggestions($member, self::PAGE_SIZE + 1, $offset),
            default => $follows->findPublicActivityFor($member, self::PAGE_SIZE + 1, $offset),
        };
        $hasNext = count($entries) > self::PAGE_SIZE;

        $response = $this->render('member/follows.html.twig', [
            'member' => $member,
            'view' => $view,
            'following_count' => $follows->countVisibleFollowing($member),
            'follower_count' => $follows->countVisibleFollowers($member),
            'entries' => array_slice($entries, 0, self::PAGE_SIZE),
            'page' => $page,
            'has_next' => $hasNext,
        ]);

        return $this->noStore($response);
    }

    #[Route(
        '/konto/folgen/{handle}/{action}',
        name: 'member_follow_action',
        requirements: ['handle' => '[a-z0-9][a-z0-9_-]{2,31}', 'action' => 'folgen|entfolgen'],
        methods: ['POST'],
    )]
    public function change(
        string $handle,
        string $action,
        Request $request,
        MemberAccountRepository $members,
        MemberFollowRepository $follows,
        #[Autowire(service: 'limiter.member_follow_account')] RateLimiterFactory $accountLimiter,
        #[Autowire(service: 'limiter.member_follow_ip')] RateLimiterFactory $ipLimiter,
        RateLimitKeyFactory $rateLimitKeys,
        LoggerInterface $logger,
    ): Response {
        $member = $this->currentMember();
        $payload = $request->request->all();
        if (array_keys($payload) !== ['_token'] || !is_string($payload['_token'] ?? null)) {
            return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
        }

        $tokenId = 'member_follow_'.$action.'_'.$handle;
        if (!$this->isCsrfTokenValid($tokenId, $payload['_token'])) {
            return $this->noStore(new Response('', Response::HTTP_FORBIDDEN));
        }

        try {
            $accountKey = $rateLimitKeys->forIdentity($member->getId(), 'member-follow-account');
            $ipKey = $rateLimitKeys->forIp($request, 'member-follow-ip');
            $accountLimit = $accountLimiter->create($accountKey)->consume();
            $ipLimit = $ipLimiter->create($ipKey)->consume();
        } catch (\Throwable $exception) {
            $logger->error('Mitgliedsfolgen wurde wegen eines Drosselungsfehlers geschlossen.', [
                'failure_type' => $exception::class,
            ]);

            return $this->noStore(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
        }

        if (!$accountLimit->isAccepted() || !$ipLimit->isAccepted()) {
            return $this->noStore(new Response('', Response::HTTP_TOO_MANY_REQUESTS));
        }

        $target = $members->findOneBy(['handle' => $handle]);
        if (!$target instanceof MemberAccount) {
            return $this->noStore(new Response('', Response::HTTP_NOT_FOUND));
        }

        try {
            if ('folgen' === $action) {
                if ($member->getId() === $target->getId()) {
                    return $this->noStore(new Response('', Response::HTTP_BAD_REQUEST));
                }
                if (!$target->isActive() || !$target->isEmailVerified() || !$target->isPublicProfile()) {
                    return $this->noStore(new Response('', Response::HTTP_NOT_FOUND));
                }

                if ($follows->follow($member, $target, new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
                    $this->addFlash('member_success', 'Du folgst jetzt diesem Mitglied.');
                } elseif ($follows->hasFollowed($member, $target)) {
                    $this->addFlash('member_success', 'Du folgst diesem Mitglied bereits.');
                } else {
                    return $this->noStore(new Response('', Response::HTTP_NOT_FOUND));
                }
            } else {
                $follows->unfollow($member, $target);
                $this->addFlash('member_success', 'Du folgst diesem Mitglied nicht mehr.');
            }
        } catch (\Throwable $exception) {
            $logger->error('Eine Mitgliedsbeziehung konnte nicht sicher gespeichert werden.', [
                'failure_type' => $exception::class,
            ]);

            return $this->noStore(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
        }

        return $this->noStore($this->redirectToRoute(
            'public_member_profile',
            ['handle' => $target->getHandle()],
            Response::HTTP_SEE_OTHER,
        ));
    }

    private function currentMember(): MemberAccount
    {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount || !$member->isActive() || !$member->isEmailVerified()) {
            throw $this->createAccessDeniedException();
        }

        return $member;
    }

    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
