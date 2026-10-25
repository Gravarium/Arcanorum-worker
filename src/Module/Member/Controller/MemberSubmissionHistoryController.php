<?php

declare(strict_types=1);

namespace App\Module\Member\Controller;

use App\Entity\MemberAccount;
use App\Repository\VideoSubmissionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MemberSubmissionHistoryController extends AbstractController
{
    #[Route('/konto/einreichungen', name: 'member_submission_history', methods: ['GET'])]
    public function index(VideoSubmissionRepository $submissions): Response
    {
        $member = $this->getUser();
        if (!$member instanceof MemberAccount) {
            throw $this->createAccessDeniedException();
        }

        $response = $this->render('member/submissions.html.twig', [
            'member' => $member,
            'submissions' => $submissions->findForSubmitter($member),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
