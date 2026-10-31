<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\PublishingStatus;
use App\Entity\VideoReport;
use App\Entity\VideoReportStatus;
use App\Repository\VideoReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/video-reports', name: 'admin_video_report_')]
#[IsGranted('ROLE_MODERATOR_VIDEO')]
final class VideoReportAdminController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, VideoReportRepository $reports): Response
    {
        $search = mb_substr(trim($request->query->getString('q')), 0, 150);
        $status = VideoReportStatus::tryFrom($request->query->getString('status'));

        return $this->render('admin/videos/reports.html.twig', [
            'reports' => $reports->findForModeration($status, $search),
            'search' => $search,
            'selected_status' => $status->value ?? '',
            'statuses' => VideoReportStatus::cases(),
        ]);
    }

    #[Route(
        '/{id}/moderate',
        name: 'moderate',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function moderate(
        string $id,
        Request $request,
        VideoReportRepository $reports,
        EntityManagerInterface $entityManager,
    ): Response {
        $report = $reports->find($id);
        if (!$report instanceof VideoReport) {
            throw $this->createNotFoundException('Videomeldung wurde nicht gefunden.');
        }
        if (!$this->isCsrfTokenValid('admin_video_report_'.$report->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (VideoReportStatus::Pending !== $report->getStatus()) {
            $this->addFlash('error', 'Diese Videomeldung wurde bereits bearbeitet.');

            return $this->redirectToRoute('admin_video_report_index');
        }

        $action = $request->request->getString('action');
        if ('dismiss' === $action) {
            $report->resolve(VideoReportStatus::Dismissed);
            $this->addFlash('success', 'Videomeldung wurde als erledigt markiert.');
        } elseif ('hide' === $action) {
            $report->getVideo()->setPublishingStatus(PublishingStatus::Draft);
            $report->resolve(VideoReportStatus::VideoHidden);
            $this->addFlash('success', 'Das Video wurde in den Entwurfsstatus verschoben.');
        } else {
            $this->addFlash('error', 'Die gewählte Moderationsaktion ist ungültig.');

            return $this->redirectToRoute('admin_video_report_index');
        }

        $entityManager->flush();

        return $this->redirectToRoute('admin_video_report_index');
    }
}
