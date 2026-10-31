<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\CommentContentType;
use App\Entity\CommentProvider;
use App\Entity\ContentCategoryType;
use App\Entity\ContentComment;
use App\Entity\MemberAccount;
use App\Entity\MemberVideoCollection;
use App\Entity\Video;
use App\Entity\VideoReport;
use App\Entity\VideoReportReason;
use App\Entity\VideoSourceType;
use App\Module\Member\Service\VideoEmailShareSender;
use App\Module\Video\Service\VideoEmbedCodeGenerator;
use App\Module\Video\Service\VideoQualitySourceResolver;
use App\Module\Video\Service\VideoStreamSource;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Repository\ContentCategoryRepository;
use App\Repository\ContentCommentRepository;
use App\Repository\ContentRatingRepository;
use App\Repository\MemberSavedVideoRepository;
use App\Repository\MemberVideoHistoryRepository;
use App\Repository\PlaylistRepository;
use App\Repository\VideoRepository;
use App\Repository\VideoSubtitleTrackRepository;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\PublicShareMetadataFactory;
use App\Service\SiteSettingsProvider;
use App\Service\VideoViewCounter;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/videos', name: 'video_')]
final class VideoPublicController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        VideoRepository $videos,
        ContentCategoryRepository $categories,
        SiteSettingsProvider $siteSettings,
    ): Response {
        $pageSize = $siteSettings->current()->getPublicVideoPageLimit();
        $search = mb_substr(trim($request->query->getString('q')), 0, 100);
        $category = mb_substr(trim($request->query->getString('category')), 0, 100);
        $tag = mb_substr(trim($request->query->getString('tag')), 0, 100);
        $page = self::pageNumber($request);
        $items = $videos->findPublished(
            search: $search,
            limit: $pageSize + 1,
            offset: ($page - 1) * $pageSize,
            category: '' === $category ? null : $category,
            tag: '' === $tag ? null : $tag,
        );

        return $this->render('videos/index.html.twig', [
            'videos' => array_slice($items, 0, $pageSize),
            'search' => $search,
            'category' => $category,
            'tag' => $tag,
            'categories' => $categories->findForAdmin(ContentCategoryType::Video),
            'page' => $page,
            'has_next' => count($items) > $pageSize,
            'popular' => false,
            'popular_sort' => null,
        ]);
    }

    #[Route('/popular', name: 'popular', methods: ['GET'])]
    public function popular(Request $request, VideoRepository $videos, SiteSettingsProvider $siteSettings): Response
    {
        $settings = $siteSettings->current();
        $pageSize = $settings->getPublicVideoPageLimit();
        $page = self::pageNumber($request);
        $items = $videos->findPopular(
            $pageSize + 1,
            ($page - 1) * $pageSize,
            $settings->getPopularVideoSort(),
            $settings->getPopularVideoPeriodDays(),
        );

        return $this->render('videos/index.html.twig', [
            'videos' => array_slice($items, 0, $pageSize),
            'search' => '',
            'category' => '',
            'tag' => '',
            'categories' => [],
            'page' => $page,
            'has_next' => count($items) > $pageSize,
            'popular' => true,
            'popular_sort' => $settings->getPopularVideoSort()->value,
        ]);
    }

    #[Route(
        '/{id}',
        name: 'show',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['GET'],
    )]
    public function show(
        string $id,
        Request $request,
        VideoRepository $videos,
        ContentCommentRepository $comments,
        ContentRatingRepository $ratings,
        ExternalVideoEmbedResolver $embedResolver,
        VideoEmbedCodeGenerator $embedCodeGenerator,
        VideoStreamSourceResolver $streamResolver,
        PlaylistRepository $playlists,
        VideoViewCounter $viewCounter,
        VideoSubtitleTrackRepository $subtitleTracks,
        VideoQualitySourceResolver $qualitySourceResolver,
        MemberSavedVideoRepository $savedVideos,
        MemberVideoHistoryRepository $videoHistory,
        SiteSettingsProvider $siteSettings,
        VideoEmailShareSender $videoEmailShareSender,
        PublicShareMetadataFactory $shareMetadata,
        LoggerInterface $logger,
    ): Response {
        $video = $videos->findPublishedById($id);
        if (!$video instanceof Video) {
            return $this->render('public/not_found.html.twig', [
                'content_type' => 'Video',
            ], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $viewCounter->record($video, $request);

        $member = $this->getUser();
        if ($member instanceof MemberAccount && $member->isActive() && $member->isEmailVerified()) {
            try {
                $videoHistory->recordPublicVisit($member, $video);
            } catch (\Throwable $exception) {
                $logger->warning('Ein privater Videoverlauf konnte nicht gespeichert werden.', ['failure_type' => $exception::class]);
            }
        }
        $memberPlaylists = $member instanceof MemberAccount ? $playlists->findOwnedBy($member) : [];
        $memberId = $member instanceof MemberAccount ? $member->getId() : null;
        $memberVideoLibrary = $member instanceof MemberAccount && $member->isActive() && $member->isEmailVerified()
            ? [
                'watch_later' => $savedVideos->contains($member, $video->getId(), MemberVideoCollection::WatchLater),
                'favorite' => $savedVideos->contains($member, $video->getId(), MemberVideoCollection::Favorite),
            ]
            : null;
        $settings = $siteSettings->current();
        $approvedComments = CommentProvider::Native === $settings->getCommentProvider()
            ? $comments->findApprovedForContent(CommentContentType::Video, $video->getId())
            : [];
        $relatedVideos = $settings->getRelatedVideoLimit() > 0
            ? $videos->findRelatedPublished($video, $settings->getRelatedVideoLimit())
            : [];
        $topRatedVideos = $settings->getTopRatedVideoLimit() > 0
            ? $videos->findTopRatedPublished($settings->getTopRatedVideoLimit(), $video->getCategory(), $video->getId())
            : [];

        $streamSource = VideoSourceType::Stream === $video->getSourceType()
            ? $streamResolver->resolve($video->getSourceUrl())
            : null;
        if (null !== $streamSource) {
            $request->attributes->set(VideoStreamSource::REQUEST_ATTRIBUTE, $streamSource->origin);
            if ($streamSource->isHls()) {
                $request->attributes->set(VideoStreamSource::HLS_REQUEST_ATTRIBUTE, true);
            }
        }
        $qualitySources = $qualitySourceResolver->resolveForPublishedVideo($video, $request);

        $response = $this->render('videos/show.html.twig', [
            'video' => $video,
            'member_playlists' => $memberPlaylists,
            'member_video_library' => $memberVideoLibrary,
            'video_email_share_available' => $member instanceof MemberAccount
                && $member->isActive()
                && $member->isEmailVerified()
                && $videoEmailShareSender->isReady(),
            'embed_url' => VideoSourceType::Embed === $video->getSourceType() ? $embedResolver->resolve($video->getSourceUrl()) : null,
            'embed_code' => $embedCodeGenerator->generate($video),
            'stream_source' => $streamSource,
            'comments' => $approvedComments,
            'video_rating' => $ratings->summaryForVideo($video->getId(), $memberId),
            'comment_rating_summaries' => $ratings->summariesForComments(
                array_map(static fn (ContentComment $comment): string => $comment->getId(), $approvedComments),
                $memberId,
            ),
            'video_report_reasons' => VideoReportReason::cases(),
            'subtitle_tracks' => $subtitleTracks->findForVideo($video),
            'quality_sources' => $qualitySources,
            'social_metadata' => $shareMetadata->forVideo($video),
            'related_videos' => $relatedVideos,
            'top_rated_videos' => $topRatedVideos,
            'video_share_widget_enabled' => $settings->isVideoShareWidgetEnabled(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route(
        '/{id}/report',
        name: 'report',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['POST'],
    )]
    public function report(
        string $id,
        Request $request,
        VideoRepository $videos,
        \Doctrine\ORM\EntityManagerInterface $entityManager,
    ): Response {
        $video = $videos->findPublishedById($id);
        if (!$video instanceof Video) {
            return $this->render('public/not_found.html.twig', [
                'content_type' => 'Video',
            ], new Response(status: Response::HTTP_NOT_FOUND));
        }

        if (!$this->isCsrfTokenValid('public_video_report_'.$video->getId(), $request->request->getString('_token'))) {
            return new Response('', Response::HTTP_FORBIDDEN, ['Cache-Control' => 'no-store']);
        }

        $routeParameters = ['id' => $video->getId(), '_fragment' => 'video-report'];
        if ('' !== trim($request->request->getString('website'))) {
            return $this->redirectToRoute('video_show', $routeParameters);
        }

        $session = $request->getSession();
        $previousReport = $session->get('public_video_last_report');
        if (is_int($previousReport) && time() - $previousReport < 15) {
            $this->addFlash('public_video_report_error', 'Bitte warte kurz, bevor du ein weiteres Video meldest.');

            return $this->redirectToRoute('video_show', $routeParameters);
        }

        $reason = VideoReportReason::tryFrom($request->request->getString('reason'));
        $details = trim($request->request->getString('details'));
        if (null === $reason
            || 1 !== preg_match('//u', $details)
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $details)
            || mb_strlen($details, 'UTF-8') > 1000
        ) {
            $this->addFlash('public_video_report_error', 'Bitte wähle einen gültigen Grund und gib höchstens 1000 Zeichen Zusatzinformation ein.');

            return $this->redirectToRoute('video_show', $routeParameters);
        }

        $report = new VideoReport($video, $reason, $details);
        $entityManager->persist($report);
        $entityManager->flush();
        $session->set('public_video_last_report', time());
        $this->addFlash('public_video_report_success', 'Danke. Die Videomeldung wurde zur Prüfung eingereicht.');

        return $this->redirectToRoute('video_show', $routeParameters);
    }

    private static function pageNumber(Request $request): int
    {
        $value = $request->query->getString('page', '1');
        $page = ctype_digit($value) ? (int) $value : 1;

        return max(1, min(5000, $page));
    }
}
