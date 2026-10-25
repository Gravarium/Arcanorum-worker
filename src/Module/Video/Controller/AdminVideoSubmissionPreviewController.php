<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\VideoSourceType;
use App\Entity\VideoSubmission;
use App\Entity\VideoSubmissionStatus;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\VideoSubmissionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/video-submissions', name: 'admin_video_submission_')]
#[IsGranted('ROLE_MODERATOR_VIDEO')]
final class AdminVideoSubmissionPreviewController extends AbstractController
{
    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['video/mp4', 'video/webm', 'video/ogg'];

    #[Route(
        '/{id}/preview',
        name: 'preview',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['GET'],
    )]
    public function preview(
        string $id,
        Request $request,
        VideoSubmissionRepository $submissions,
        PrivateObjectStorageInterface $storage,
    ): Response {
        $submission = $submissions->find($id);
        if (!$submission instanceof VideoSubmission
            || VideoSubmissionStatus::Pending !== $submission->getStatus()
        ) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $video = $submission->getVideo();
        $objectId = $video->getUploadedObjectId();
        $mimeType = $video->getUploadedMimeType();
        $size = $video->getUploadedSize();
        if (VideoSourceType::File !== $video->getSourceType()
            || null === $objectId
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $objectId)
            || null === $mimeType
            || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)
            || null === $size
            || $size < 1
            || $size > 33554432
        ) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $range = null;
        $rangeHeader = $request->headers->get('Range');
        if (null !== $rangeHeader) {
            $range = self::parseRange($rangeHeader, $size);
            if (null === $range) {
                return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                    'Accept-Ranges' => 'bytes',
                    'Cache-Control' => 'private, no-store',
                    'Content-Range' => 'bytes */'.$size,
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        try {
            $contents = $storage->read($objectId);
        } catch (PrivateStorageOperationFailed) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Cache-Control' => 'private, no-store',
                'Retry-After' => '60',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $actualMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (strlen($contents) !== $size || $actualMimeType !== $mimeType) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Cache-Control' => 'private, no-store',
                'Retry-After' => '60',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $body = $contents;
        $status = Response::HTTP_OK;
        $headers = [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline',
            'Content-Type' => $mimeType,
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ];
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
