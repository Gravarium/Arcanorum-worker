<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\VideoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/videos', name: 'video_')]
final class VideoMediaController extends AbstractController
{
    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['video/mp4', 'video/webm', 'video/ogg', 'audio/mpeg'];

    #[Route(
        '/{id}/media',
        name: 'media',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['GET'],
    )]
    public function media(
        string $id,
        Request $request,
        VideoRepository $videos,
        PrivateObjectStorageInterface $storage,
    ): Response {
        $video = $videos->findPublishedById($id);
        if (!$video instanceof Video) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $objectId = $video->getUploadedObjectId();
        $mimeType = $video->getUploadedMimeType();
        $size = $video->getUploadedSize();
        if (VideoSourceType::File !== $video->getSourceType()
            || null === $objectId
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $objectId)
            || null === $mimeType
            || null === $size
            || $size < 1
            || $size > 33554432
            || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)
        ) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }
        $rangeHeader = $request->headers->get('Range');
        $range = null;
        if (null !== $rangeHeader) {
            $range = self::parseRange($rangeHeader, $size);
            if (null === $range) {
                return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                    'Accept-Ranges' => 'bytes',
                    'Cache-Control' => 'no-store',
                    'Content-Range' => 'bytes */'.$size,
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        try {
            $contents = $storage->read($objectId, $size);
        } catch (PrivateStorageOperationFailed) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Cache-Control' => 'no-store',
                'Retry-After' => '60',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $actualMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (strlen($contents) !== $size || $actualMimeType !== $mimeType) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Cache-Control' => 'no-store',
                'Retry-After' => '60',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $headers = [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'public, max-age=300',
            'Content-Disposition' => 'inline',
            'Content-Type' => $mimeType,
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $body = $contents;
        $status = Response::HTTP_OK;
        if (null !== $range) {
            [$start, $end] = $range;
            $body = substr($contents, $start, $end - $start + 1);
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
            $status = Response::HTTP_PARTIAL_CONTENT;
        }
        $headers['Content-Length'] = (string) strlen($body);

        return new Response($body, $status, $headers);
    }

    /**
     * @return array{int, int}|null
     */
    private static function parseRange(string $header, int $size): ?array
    {
        if ($size < 1 || strlen($header) > 100
            || 1 !== preg_match('/\Abytes=(\d*)-(\d*)\z/D', $header, $matches)
        ) {
            return null;
        }

        $rangeStart = $matches[1];
        $rangeEnd = $matches[2];
        if ('' === $rangeStart && '' === $rangeEnd) {
            return null;
        }

        if ('' === $rangeStart) {
            $suffixLength = (int) $rangeEnd;
            if ($suffixLength < 1) {
                return null;
            }

            return [max(0, $size - $suffixLength), $size - 1];
        }

        $start = (int) $rangeStart;
        if ($start >= $size) {
            return null;
        }

        $end = '' === $rangeEnd ? $size - 1 : min((int) $rangeEnd, $size - 1);
        if ($end < $start) {
            return null;
        }

        return [$start, $end];
    }
}
