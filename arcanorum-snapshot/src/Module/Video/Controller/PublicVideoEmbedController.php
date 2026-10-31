<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoEmbedOriginPolicy;
use App\Module\Video\Service\VideoQualitySourceResolver;
use App\Module\Video\Service\VideoStreamSource;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Repository\VideoRepository;
use App\Repository\VideoSubtitleTrackRepository;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\SiteSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/embed/video', name: 'video_embed_')]
final class PublicVideoEmbedController extends AbstractController
{
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
        VideoEmbedOriginPolicy $originPolicy,
        VideoStreamSourceResolver $streamResolver,
        ExternalVideoEmbedResolver $externalEmbedResolver,
        VideoSubtitleTrackRepository $subtitleTracks,
        VideoQualitySourceResolver $qualitySourceResolver,
        SiteSettingsProvider $settings,
    ): Response {
        $siteSettings = $settings->current();
        if (!$siteSettings->isPublicVideoEmbeddingEnabled()) {
            return self::notFound();
        }

        $video = $videos->findPublishedById($id);
        if (!$video instanceof Video || !$video->isExternallyEmbeddable()) {
            return self::notFound();
        }

        $allowedOrigins = $originPolicy->validateStoredOrigins($video->getEmbedAllowedOrigins());
        if (null === $allowedOrigins || [] === $allowedOrigins) {
            return self::notFound();
        }

        $streamSource = null;
        $externalEmbedUrl = null;
        if (VideoSourceType::File === $video->getSourceType()) {
            $objectId = $video->getUploadedObjectId();
            $mimeType = $video->getUploadedMimeType();
            $size = $video->getUploadedSize();
            if (null === $objectId
                || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
                || !in_array($mimeType, ['video/mp4', 'video/webm', 'video/ogg', 'audio/mpeg'], true)
                || null === $size
                || $size < 1
                || $size > 33554432
            ) {
                return self::notFound();
            }
        } elseif (VideoSourceType::Stream === $video->getSourceType()) {
            $streamSource = $streamResolver->resolve($video->getSourceUrl());
            if (null === $streamSource) {
                return self::notFound();
            }
            $request->attributes->set(VideoStreamSource::REQUEST_ATTRIBUTE, $streamSource->origin);
            if ($streamSource->isHls()) {
                $request->attributes->set(VideoStreamSource::HLS_REQUEST_ATTRIBUTE, true);
            }
        } elseif (VideoSourceType::Embed === $video->getSourceType()) {
            $externalEmbedUrl = $externalEmbedResolver->resolve($video->getSourceUrl());
            if (null === $externalEmbedUrl) {
                return self::notFound();
            }
        } else {
            return self::notFound();
        }

        $qualitySources = $qualitySourceResolver->resolveForPublishedVideo($video, $request);

        $request->attributes->set(VideoEmbedSecuritySubscriber::FRAME_ANCESTORS_ATTRIBUTE, $allowedOrigins);

        $response = $this->render('videos/embed.html.twig', [
            'video' => $video,
            'stream_source' => $streamSource,
            'external_embed_url' => $externalEmbedUrl,
            'subtitle_tracks' => $subtitleTracks->findForVideo($video),
            'quality_sources' => $qualitySources,
            'player_engine' => $siteSettings->getVideoPlayerEngine()->value,
            'player_preload' => $siteSettings->getVideoPreloadMode()->value,
            'player_autoplay' => $siteSettings->isPlayerAutoplayEnabled(),
            'player_aspect_ratio' => $siteSettings->getVideoPlayerAspectRatio()->value,
            'player_max_width' => $siteSettings->getVideoPlayerEmbedMaxWidth()->value,
            'player_color_scheme' => $siteSettings->getVideoPlayerColorScheme()->value,
            'player_watermark_enabled' => $siteSettings->isVideoPlayerWatermarkEnabled(),
        ]);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private static function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
