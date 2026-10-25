<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\Video;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\VideoRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class VideoThumbnailController
{
    public function __construct(private PrivateObjectStorageInterface $storage)
    {
    }

    #[Route('/videos/{id}/thumbnail', name: 'video_thumbnail', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function show(string $id, VideoRepository $videos): Response
    {
        $video = $videos->findPublishedById($id);
        if (!$video instanceof Video) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return $this->imageResponse($video, false);
    }

    #[Route('/admin/videos/{id}/thumbnail', name: 'admin_video_thumbnail_preview', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function preview(string $id, VideoRepository $videos): Response
    {
        $video = $videos->find($id);
        if (!$video instanceof Video) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return $this->imageResponse($video, true);
    }

    private function imageResponse(Video $video, bool $private): Response
    {
        $objectId = $video->getThumbnailObjectId();
        $expectedMimeType = $video->getThumbnailMimeType();
        $expectedSize = $video->getThumbnailSize();
        if (null === $objectId || null === $expectedMimeType || null === $expectedSize) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        try {
            $contents = $this->storage->read($objectId);
        } catch (PrivateStorageOperationFailed) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        $detectedMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (strlen($contents) !== $expectedSize
            || !is_string($detectedMimeType)
            || $detectedMimeType !== $expectedMimeType
            || !in_array($detectedMimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
        ) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $detectedMimeType,
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => $private ? 'private, no-store' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
