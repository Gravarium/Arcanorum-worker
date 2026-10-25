<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\CommentContentType;
use App\Entity\ContentCategoryType;
use App\Entity\MemberAccount;
use App\Entity\Video;
use App\Entity\VideoReport;
use App\Entity\VideoReportReason;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoStreamSource;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Repository\ContentCategoryRepository;
use App\Repository\ContentCommentRepository;
use App\Repository\PlaylistRepository;
use App\Repository\VideoRepository;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\VideoViewCounter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/videos', name: 'video_')]
final class VideoPublicController extends AbstractController
{
    private const int PAGE_SIZE = 24;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, VideoRepository $videos, ContentCategoryRepository $categories): Response
    {
        $search = mb_substr(trim($request->query->getString('q')), 0, 100);
        $category = mb_substr(trim($request->query->getString('category')), 0, 100);
        $tag = mb_substr(trim($request->query->getString('tag')), 0, 100);
        $page = self::pageNumber($request);
        $items = $videos->findPublished(
            search: $search,
            limit: self::PAGE_SIZE + 1,
            offset: ($page - 1) * self::PAGE_SIZE,
            category: '' === $category ? null : $category,
            tag: '' === $tag ? null : $tag,
        );

        return $this->render('videos/index.html.twig', [
            'videos' => array_slice($items, 0, self::PAGE_SIZE),
            'search' => $search,
            'category' => $category,
            'tag' => $tag,
            'categories' => $categories->findForAdmin(ContentCategoryType::Video),
            'page' => $page,
            'has_next' => count($items) > self::PAGE_SIZE,
            'popular' => false,
        ]);
    }

    #[Route('/popular', name: 'popular', methods: ['GET'])]
    public function popular(Request $request, VideoRepository $videos): Response
    {
        $page = self::pageNumber($request);
        $items = $videos->findPopular(self::PAGE_SIZE + 1, ($page - 1) * self::PAGE_SIZE);

        return $this->render('videos/index.html.twig', [
            'videos' => array_slice($items, 0, self::PAGE_SIZE),
            'search' => '',
            'category' => '',
            'tag' => '',
            'categories' => [],
            'page' => $page,
            'has_next' => count($items) > self::PAGE_SIZE,
            'popular' => true,
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
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
        PlaylistRepository $playlists,
        VideoViewCounter $viewCounter,
    ): Response {
        $video = $videos->findPublishedById($id);
        if (!$video instanceof Video) {
            return $this->render('public/not_found.html.twig', [
                'content_type' => 'Video',
            ], new Response(status: Response::HTTP_NOT_FOUND));
        }

        $viewCounter->record($video, $request);

        $member = $this->getUser();
        $memberPlaylists = $member instanceof MemberAccount ? $playlists->findOwnedBy($member) : [];

        $streamSource = VideoSourceType::Stream === $video->getSourceType()
            ? $streamResolver->resolve($video->getSourceUrl())
            : null;
        if (null !== $streamSource) {
            $request->attributes->set(VideoStreamSource::REQUEST_ATTRIBUTE, $streamSource->origin);
            if ($streamSource->isHls()) {
                $request->attributes->set(VideoStreamSource::HLS_REQUEST_ATTRIBUTE, true);
            }
        }

        $response = $this->render('videos/show.html.twig', [
            'video' => $video,
            'member_playlists' => $memberPlaylists,
            'embed_url' => VideoSourceType::Embed === $video->getSourceType() ? $embedResolver->resolve($video->getSourceUrl()) : null,
            'stream_source' => $streamSource,
            'comments' => $comments->findApprovedForContent(CommentContentType::Video, $video->getId()),
            'video_report_reasons' => VideoReportReason::cases(),
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
