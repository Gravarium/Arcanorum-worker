<?php

declare(strict_types=1);

namespace App\Module\Video\Controller;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentCategoryRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class VideoCategoryArtworkController
{
    private const int MAX_FILE_BYTES = 1048576;

    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private PrivateObjectStorageInterface $storage)
    {
    }

    #[Route(
        '/videos/category/{slug}/artwork',
        name: 'video_category_artwork',
        requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['GET'],
        stateless: true,
    )]
    public function show(string $slug, ContentCategoryRepository $categories): Response
    {
        $category = $categories->findForTypeAndSlug(ContentCategoryType::Video, $slug);
        if (!$category instanceof ContentCategory) {
            return self::notFound();
        }

        $objectId = $category->getArtworkObjectId();
        $expectedMimeType = $category->getArtworkMimeType();
        $expectedSize = $category->getArtworkSize();
        $expectedSha256 = $category->getArtworkSha256();
        if (null === $objectId
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
            || null === $expectedMimeType
            || !in_array($expectedMimeType, self::ALLOWED_MIME_TYPES, true)
            || null === $expectedSize
            || $expectedSize < 1
            || $expectedSize > self::MAX_FILE_BYTES
            || null === $expectedSha256
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256)
        ) {
            return self::notFound();
        }

        try {
            $contents = $this->storage->read($objectId, $expectedSize);
        } catch (PrivateStorageOperationFailed) {
            return self::unavailable();
        }

        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $imageInfo = @getimagesizefromstring($contents);
        if (strlen($contents) !== $expectedSize
            || !is_string($mimeType)
            || $mimeType !== $expectedMimeType
            || !hash_equals($expectedSha256, hash('sha256', $contents))
            || false === $imageInfo
            || $imageInfo[0] < 1
            || $imageInfo[0] > 320
            || $imageInfo[1] < 1
            || $imageInfo[1] > 180
            || $imageInfo['mime'] !== $expectedMimeType
        ) {
            return self::unavailable();
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $expectedMimeType,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'public, max-age=300',
            'Cross-Origin-Resource-Policy' => 'same-origin',
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
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
