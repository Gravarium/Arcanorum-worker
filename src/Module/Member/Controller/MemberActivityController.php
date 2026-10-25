<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\MemberAccount;
use App\Repository\ContentCommentRepository;
use App\Repository\PlaylistRepository;
use App\Repository\VideoSubmissionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_MEMBER')]
final class MemberActivityController extends AbstractController
{
    private const int PAGE_SIZE = 25;

    #[Route('/konto/aktivitaeten', name: 'member_activity_index', methods: ['GET'])]
    public function index(
        Request $request,
        ContentCommentRepository $comments,
        PlaylistRepository $playlists,
        VideoSubmissionRepository $submissions,
    ): Response {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount) {
            throw $this->createAccessDeniedException();
        }

        $rawPage = $request->query->all()['page'] ?? '1';
        if (!is_string($rawPage) || !ctype_digit($rawPage) || strlen($rawPage) > 4
            || (int) $rawPage < 1 || (int) $rawPage > 5000
        ) {
            return new Response('', Response::HTTP_BAD_REQUEST, [
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }
        $page = (int) $rawPage;
        $items = $comments->findForMember($member, self::PAGE_SIZE + 1, ($page - 1) * self::PAGE_SIZE);
        $response = $this->render('member/activity.html.twig', [
            'member' => $member,
            'comments' => array_slice($items, 0, self::PAGE_SIZE),
            'playlists' => 1 === $page ? $playlists->findRecentForOwner($member, 5) : [],
            'submissions' => 1 === $page ? array_slice($submissions->findForSubmitter($member), 0, 5) : [],
            'page' => $page,
            'has_next' => count($items) > self::PAGE_SIZE,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
