<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\Video;
use App\Module\Video\Service\VideoAccessPolicy;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\VideoRepository;
use App\Service\VideoSocialPreviewGenerator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class VideoThumbnailController
{
    private const int MAX_FILE_BYTES = 4194304;
    private const int MAX_DIMENSION = 4096;

    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private PrivateObjectStorageInterface $storage,
        private VideoSocialPreviewGenerator $socialPreviews,
        private Security $security,
        private VideoAccessPolicy $videoAccess,
    ) {
    }

    #[Route('/videos/{id}/thumbnail', name: 'video_thumbnail', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function show(string $id, Request $request, VideoRepository $videos): Response
    {
        $video = $videos->findPublishedById($id);
        if (!$video instanceof Video || !$this->videoAccess->canWatch($video, $this->security->getUser())) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return $this->imageResponse($video, false, $request);
    }

    #[Route(
        '/videos/{id}/social-preview/{version}',
        name: 'video_social_preview',
        requirements: ['id' => '[a-f0-9]{32}', 'version' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function socialPreview(string $id, string $version, Request $request, VideoRepository $videos): Response
    {
        $video = $videos->findPublishedById($id);
        if (!$video instanceof Video || !$this->videoAccess->canWatch($video, $this->security->getUser())) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        $objectId = $video->getThumbnailObjectId();
        $mimeType = $video->getThumbnailMimeType();
        $size = $video->getThumbnailSize();
        if (null === $objectId
            || null === $mimeType
            || null === $size
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
            || !hash_equals(VideoSocialPreviewGenerator::versionForSourceObjectId($objectId), $version)
        ) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        try {
            $contents = $this->socialPreviews->generate($objectId, $mimeType, $size);
        } catch (PrivateStorageOperationFailed) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        $response = new Response($contents, Response::HTTP_OK, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag(hash('sha256', $contents));
        $response->isNotModified($request);

        return $response;
    }

    #[Route('/admin/videos/{id}/thumbnail', name: 'admin_video_thumbnail_preview', requirements: ['id' => '[a-f0-9]{32}'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function preview(string $id, Request $request, VideoRepository $videos): Response
    {
        $video = $videos->find($id);
        if (!$video instanceof Video) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return $this->imageResponse($video, true, $request);
    }

    private function imageResponse(Video $video, bool $private, Request $request): Response
    {
        $objectId = $video->getThumbnailObjectId();
        $expectedMimeType = $video->getThumbnailMimeType();
        $expectedSize = $video->getThumbnailSize();
        if (null === $objectId
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $objectId)
            || null === $expectedMimeType
            || null === $expectedSize
            || !in_array($expectedMimeType, self::ALLOWED_MIME_TYPES, true)
            || $expectedSize < 1
            || $expectedSize > self::MAX_FILE_BYTES
        ) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        try {
            $contents = $this->storage->read($objectId, $expectedSize);
        } catch (PrivateStorageOperationFailed) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        $detectedMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $imageInfo = @getimagesizefromstring($contents);
        if (strlen($contents) !== $expectedSize
            || !is_string($detectedMimeType)
            || $detectedMimeType !== $expectedMimeType
            || false === $imageInfo
            || $detectedMimeType !== $imageInfo['mime']
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] > self::MAX_DIMENSION
            || $imageInfo[1] > self::MAX_DIMENSION
        ) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        $response = new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $detectedMimeType,
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => $private ? 'private, no-store' : 'public, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if (!$private) {
            $response->setEtag(hash('sha256', $contents));
            $response->isNotModified($request);
        }

        return $response;
    }
}
