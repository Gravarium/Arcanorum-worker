<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ContentInlineImage;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use Symfony\Component\HttpFoundation\Response;

final readonly class ContentInlineImageReader
{
    private const int MAX_FILE_BYTES = 4_194_304;
    private const int MAX_DIMENSION = 4096;
    private const int MAX_PIXELS = 8_388_608;

    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private PrivateObjectStorageInterface $storage)
    {
    }

    public function response(ContentInlineImage $image, bool $private): Response
    {
        $objectId = $image->getId();
        $mimeType = $image->getMimeType();
        $size = $image->getSize();
        $sha256 = $image->getSha256();
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/', $objectId)
            || $size < 1
            || $size > self::MAX_FILE_BYTES
            || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $sha256)
        ) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        try {
            $contents = $this->storage->read($objectId, $size);
        } catch (PrivateStorageOperationFailed) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        $detectedMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $imageInfo = @getimagesizefromstring($contents);
        if (strlen($contents) !== $size
            || !is_string($detectedMimeType)
            || $detectedMimeType !== $mimeType
            || !hash_equals($sha256, hash('sha256', $contents))
            || false === $imageInfo
            || $detectedMimeType !== $imageInfo['mime']
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] > self::MAX_DIMENSION
            || $imageInfo[1] > self::MAX_DIMENSION
            || $imageInfo[0] * $imageInfo[1] > self::MAX_PIXELS
        ) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $detectedMimeType,
            'Content-Length' => (string) $size,
            'Cache-Control' => $private ? 'private, no-store' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
            'Content-Security-Policy' => "default-src 'none'; sandbox; frame-ancestors 'none'; base-uri 'none'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
