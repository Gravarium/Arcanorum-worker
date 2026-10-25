<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\PublishingStatus;
use App\Entity\VideoSubmission;
use App\Entity\VideoSubmissionStatus;
use App\Repository\VideoSubmissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/video-submissions', name: 'admin_video_submission_')]
#[IsGranted('ROLE_MODERATOR_VIDEO')]
final class VideoSubmissionAdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(VideoSubmissionRepository $submissions): Response
    {
        return $this->render('admin/videos/submissions.html.twig', [
            'submissions' => $submissions->findPending(),
        ]);
    }

    #[Route(
        '/{id}/review',
        name: 'review',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function review(
        string $id,
        Request $request,
        VideoSubmissionRepository $submissions,
        EntityManagerInterface $entityManager,
    ): Response {
        $submission = $submissions->find($id);
        if (!$submission instanceof VideoSubmission) {
            throw $this->createNotFoundException('Videoeinreichung wurde nicht gefunden.');
        }
        if (!$this->isCsrfTokenValid('admin_video_submission_'.$submission->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (VideoSubmissionStatus::Pending !== $submission->getStatus()) {
            $this->addFlash('error', 'Diese Videoeinreichung wurde bereits bearbeitet.');

            return $this->redirectToRoute('admin_video_submission_index');
        }

        $action = $request->request->getString('action');
        if ('approve' === $action) {
            $submission->getVideo()->setPublishingStatus(PublishingStatus::Published);
            $submission->resolve(VideoSubmissionStatus::Approved);
            $this->addFlash('success', 'Die Videoeinreichung wurde veröffentlicht.');
        } elseif ('reject' === $action) {
            $submission->getVideo()->setPublishingStatus(PublishingStatus::Draft);
            $submission->resolve(VideoSubmissionStatus::Rejected);
            $this->addFlash('success', 'Die Videoeinreichung wurde abgelehnt und bleibt privat.');
        } else {
            $this->addFlash('error', 'Die gewählte Moderationsaktion ist ungültig.');

            return $this->redirectToRoute('admin_video_submission_index');
        }

        $entityManager->flush();

        return $this->redirectToRoute('admin_video_submission_index');
    }
}
