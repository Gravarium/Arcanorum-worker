<?php

declare(strict_types=1);

namespace App\Module\Platform\Controller;

use App\Entity\FeatureModule;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoQualitySourceResolver;
use App\Module\Video\Service\VideoStreamSource;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Repository\ContentTagRepository;
use App\Repository\MemberAccountRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\VideoRepository;
use App\Repository\VideoSubtitleTrackRepository;
use App\Service\SiteSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'platform_home', methods: ['GET'])]
    public function index(
        Request $request,
        NewsArticleRepository $articles,
        VideoRepository $videos,
        ContentTagRepository $tags,
        MemberAccountRepository $members,
        VideoSubtitleTrackRepository $subtitleTracks,
        VideoQualitySourceResolver $qualitySourceResolver,
        VideoStreamSourceResolver $streamResolver,
        SiteSettingsProvider $siteSettings,
    ): Response {
        $settings = $siteSettings->current();
        $featuredVideos = $videos->findFeaturedPublishedList($settings->getHomepageFeaturedLimit());
        $featuredVideo = $featuredVideos[0] ?? null;
        $streamSource = null;
        $canPlayFeatured = false;
        $featuredSubtitleTracks = [];
        $featuredQualitySources = [];
        $featuredAutoplay = false;

        if ($featuredVideo instanceof Video) {
            if (VideoSourceType::File === $featuredVideo->getSourceType()) {
                $objectId = $featuredVideo->getUploadedObjectId();
                $mimeType = $featuredVideo->getUploadedMimeType();
                $size = $featuredVideo->getUploadedSize();
                $canPlayFeatured = is_string($objectId)
                    && 1 === preg_match('/\A[a-f0-9]{64}\z/', $objectId)
                    && in_array($mimeType, ['video/mp4', 'video/webm', 'video/ogg'], true)
                    && is_int($size)
                    && $size >= 1
                    && $size <= 33554432;
            } elseif (VideoSourceType::Stream === $featuredVideo->getSourceType()) {
                $streamSource = $streamResolver->resolve($featuredVideo->getSourceUrl());
                $canPlayFeatured = null !== $streamSource;
                if (null !== $streamSource) {
                    $request->attributes->set(VideoStreamSource::REQUEST_ATTRIBUTE, $streamSource->origin);
                    if ($streamSource->isHls()) {
                        $request->attributes->set(VideoStreamSource::HLS_REQUEST_ATTRIBUTE, true);
                    }
                }
            }

            if ($canPlayFeatured) {
                $featuredSubtitleTracks = $subtitleTracks->findForVideo($featuredVideo);
                $featuredQualitySources = $qualitySourceResolver->resolveForPublishedVideo($featuredVideo, $request);
                $featuredAutoplay = $settings->isPlayerFeaturedAutoplayEnabled()
                    && in_array($featuredVideo->getSourceType(), [VideoSourceType::File, VideoSourceType::Stream], true);
            }
        }

        $featuredIds = array_fill_keys(array_map(static fn (Video $video): string => $video->getId(), $featuredVideos), true);
        $latestVideos = 0 === $settings->getHomepageVideoLimit()
            ? []
            : array_slice(
                array_values(array_filter(
                    $videos->findPublished(limit: min(50, $settings->getHomepageVideoLimit() + count($featuredVideos) + 1)),
                    static fn (Video $video): bool => !isset($featuredIds[$video->getId()]),
                )),
                0,
                $settings->getHomepageVideoLimit(),
            );
        $newsEnabled = $settings->isFeatureModuleEnabled(FeatureModule::News);
        $newsItems = !$newsEnabled || 0 === $settings->getHomepageNewsLimit()
            ? []
            : $articles->findPublished(limit: $settings->getHomepageNewsLimit());
        $publicStatistics = $settings->isPublicStatisticsEnabled()
            ? [
                'videos' => $videos->countPublicPublished(),
                'news' => $newsEnabled ? $articles->countPublicPublished() : 0,
                'members' => $members->countPublicProfiles(),
            ]
            : null;

        $response = $this->render('home/index.html.twig', [
            'articles' => $newsItems,
            'videos' => $latestVideos,
            'featured_video' => $featuredVideo,
            'featured_video_cards' => array_slice($featuredVideos, 1),
            'currently_watching_videos' => $settings->getCurrentlyWatchingLimit() > 0
                ? $videos->findCurrentlyWatchedPublished($settings->getCurrentlyWatchingLimit())
                : [],
            'public_statistics' => $publicStatistics,
            'tag_cloud' => $settings->isTagCloudEnabled()
                ? $tags->findPublicVideoCloud($settings->getTagCloudLimit(), $settings->isTagCloudShuffleEnabled())
                : [],
            'featured_player_available' => $canPlayFeatured,
            'featured_stream_source' => $streamSource,
            'featured_subtitle_tracks' => $featuredSubtitleTracks,
            'featured_quality_sources' => $featuredQualitySources,
            'featured_autoplay' => $featuredAutoplay,
            'featured_uses_videojs' => $canPlayFeatured && 'videojs' === $settings->getVideoPlayerEngine()->value,
            'featured_uses_hls' => $canPlayFeatured && null !== $streamSource && $streamSource->isHls(),
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
