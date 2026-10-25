<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubtitleTrack;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\VideoRepository;
use App\Repository\VideoSubtitleTrackRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class VideoSubtitleController
{
    public function __construct(
        private PrivateObjectStorageInterface $storage,
        private LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/videos/{videoId}/subtitles/{trackId}',
        name: 'video_subtitle_track',
        requirements: ['videoId' => '[a-f0-9]{32}', 'trackId' => '[a-f0-9]{32}'],
        methods: ['GET'],
    )]
    public function show(
        string $videoId,
        string $trackId,
        VideoRepository $videos,
        VideoSubtitleTrackRepository $tracks,
    ): Response {
        $video = $videos->findPublishedById($videoId);
        if (!$video instanceof Video
            || !in_array($video->getSourceType(), [VideoSourceType::File, VideoSourceType::Stream], true)
        ) {
            return self::notFound();
        }

        $track = $tracks->findForVideoAndId($video, $trackId);
        if (!$track instanceof VideoSubtitleTrack) {
            return self::notFound();
        }

        try {
            $contents = $this->storage->read($track->getStorageObjectId());
        } catch (PrivateStorageOperationFailed) {
            $this->logger->error('Die private Untertiteldatei konnte nicht gelesen werden.', ['track_id' => $track->getId()]);

            return self::unavailable();
        }

        if (strlen($contents) !== $track->getSize()
            || !hash_equals($track->getSha256(), hash('sha256', $contents))
            || 1 !== preg_match('/\AWEBVTT(?:[ \t][^\r\n]{0,120})?\n/', $contents)
        ) {
            $this->logger->error('Die private Untertiteldatei stimmt nicht mit den gespeicherten Metadaten überein.', ['track_id' => $track->getId()]);

            return self::unavailable();
        }

        return new Response($contents, Response::HTTP_OK, [
            'Cache-Control' => 'public, max-age=300',
            'Content-Disposition' => 'inline',
            'Content-Length' => (string) strlen($contents),
            'Content-Security-Policy' => "default-src 'none'; base-uri 'none'; frame-ancestors 'none'",
            'Content-Type' => 'text/vtt; charset=UTF-8',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private static function unavailable(): Response
    {
        return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
            'Cache-Control' => 'no-store',
            'Retry-After' => '60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
