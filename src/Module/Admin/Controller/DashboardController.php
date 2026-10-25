<?php

declare(strict_types=1);

namespace App\Module\Admin\Controller;

use App\Entity\CommentStatus;
use App\Entity\ContactMessageStatus;
use App\Entity\PublishingStatus;
use App\Entity\VideoReportStatus;
use App\Entity\VideoSubmissionStatus;
use App\Repository\ContactMessageRepository;
use App\Repository\ContentCommentRepository;
use App\Repository\ContentSeriesRepository;
use App\Repository\MemberAccountRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\SearchTermStatisticsRepository;
use App\Repository\VideoReportRepository;
use App\Repository\VideoRepository;
use App\Repository\VideoSubmissionRepository;
use App\Service\AdminPhpRuntimeSummary;
use App\Service\SystemCapabilitySummary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN_STAFF')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly SystemCapabilitySummary $systemCapabilities,
        private readonly AdminPhpRuntimeSummary $phpRuntime,
        private readonly NewsArticleRepository $newsArticles,
        private readonly VideoRepository $videos,
        private readonly ContentSeriesRepository $series,
        private readonly ContentCommentRepository $comments,
        private readonly MemberAccountRepository $members,
        private readonly VideoSubmissionRepository $submissions,
        private readonly VideoReportRepository $videoReports,
        private readonly ContactMessageRepository $contactMessages,
        private readonly SearchTermStatisticsRepository $searchStatistics,
    ) {
    }

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            if ($this->isGranted('ROLE_MODERATOR_VIDEO')) {
                return $this->redirectToRoute('admin_video_submission_index');
            }
            if ($this->isGranted('ROLE_MODERATOR_COMMENTS')) {
                return $this->redirectToRoute('admin_comment_index');
            }

            throw $this->createAccessDeniedException();
        }

        $this->searchStatistics->purgeExpired(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $system = $this->systemCapabilities->current();
        $phpRuntime = $this->phpRuntime->current();

        $response = $this->render('admin/dashboard.html.twig', [
            'capability_checks' => $system['checks'],
            'system_notices' => [...$system['notices'], ...$phpRuntime['notices']],
            'content_metrics' => [
                'news' => [
                    'total' => $this->newsArticles->countForAdmin('', null),
                    'published' => $this->newsArticles->countForAdmin('', PublishingStatus::Published),
                    'draft' => $this->newsArticles->countForAdmin('', PublishingStatus::Draft),
                ],
                'videos' => [
                    'total' => $this->videos->countForAdmin('', null),
                    'published' => $this->videos->countForAdmin('', PublishingStatus::Published),
                    'draft' => $this->videos->countForAdmin('', PublishingStatus::Draft),
                ],
                'series' => [
                    'total' => $this->series->countForAdmin(),
                    'published' => $this->series->countForAdmin(PublishingStatus::Published),
                    'draft' => $this->series->countForAdmin(PublishingStatus::Draft),
                ],
                'comments' => [
                    'pending' => $this->comments->countForStatus(CommentStatus::Pending),
                    'reported' => $this->comments->countForStatus(CommentStatus::Reported),
                ],
                'members' => [
                    'total' => $this->members->countForAdmin('', null),
                    'active' => $this->members->countForAdmin('', true),
                    'inactive' => $this->members->countForAdmin('', false),
                ],
                'submissions' => [
                    'pending' => $this->submissions->countForStatus(VideoSubmissionStatus::Pending),
                ],
                'video_reports' => [
                    'pending' => $this->videoReports->countForStatus(VideoReportStatus::Pending),
                ],
                'contact_messages' => [
                    'pending' => $this->contactMessages->countForStatus(ContactMessageStatus::Pending),
                ],
                'search_terms' => [
                    'total' => $this->searchStatistics->countForAdmin(''),
                ],
            ],
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
