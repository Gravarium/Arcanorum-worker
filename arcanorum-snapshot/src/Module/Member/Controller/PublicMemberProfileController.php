<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\MemberAccount;
use App\Repository\MemberAccountRepository;
use App\Repository\MemberFollowRepository;
use App\Repository\PlaylistRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/kanal', name: 'public_member_')]
final class PublicMemberProfileController extends AbstractController
{
    #[Route('/{handle}', name: 'profile', methods: ['GET'], requirements: ['handle' => '[a-z0-9][a-z0-9_-]{2,31}'])]
    public function profile(
        string $handle,
        MemberAccountRepository $members,
        MemberFollowRepository $follows,
        PlaylistRepository $playlists,
    ): Response {
        $member = $members->findPublicByHandle($handle);

        if (null === $member) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store, private']);
        }

        $user = $this->getUser();
        $viewer = $user instanceof MemberAccount && $user->isActive() && $user->isEmailVerified() ? $user : null;
        $canFollow = false;
        $followAction = 'folgen';
        if (null !== $viewer && $viewer->getId() !== $member->getId()) {
            $canFollow = true;
            if ($follows->hasFollowed($viewer, $member)) {
                $followAction = 'entfolgen';
            }
        }

        $response = $this->render('member/account.html.twig', [
            'page' => 'public_profile',
            'member' => $member,
            'public_playlists' => $playlists->findPublicForOwner($member),
            'viewer' => $viewer,
            'can_follow' => $canFollow,
            'follow_action' => $followAction,
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
