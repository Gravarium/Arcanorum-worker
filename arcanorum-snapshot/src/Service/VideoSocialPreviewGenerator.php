<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;

final readonly class VideoSocialPreviewGenerator
{
    private const int MAX_SOURCE_BYTES = 4194304;
    private const int MAX_SOURCE_DIMENSION = 4096;
    private const int MAX_SOURCE_PIXELS = 16777216;
    private const int MAX_PREVIEW_BYTES = 3145728;
    private const int PREVIEW_WIDTH = 1200;
    private const int PREVIEW_HEIGHT = 630;

    /** @var list<string> */
    private const array SOURCE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private PrivateObjectStorageInterface $storage)
    {
    }

    public static function versionForSourceObjectId(string $sourceObjectId): string
    {
        self::assertObjectId($sourceObjectId);

        return hash('sha256', "arcanorum-social-preview-url-v1\0".$sourceObjectId);
    }

    public static function cacheObjectIdForSourceObjectId(string $sourceObjectId): string
    {
        self::assertObjectId($sourceObjectId);

        return hash('sha256', "arcanorum-social-preview-cache-v1\0".$sourceObjectId);
    }

    public function generate(string $sourceObjectId, string $sourceMimeType, int $sourceSize): string
    {
        self::assertObjectId($sourceObjectId);
        if (!in_array($sourceMimeType, self::SOURCE_MIME_TYPES, true)
            || $sourceSize < 1
            || $sourceSize > self::MAX_SOURCE_BYTES
        ) {
            throw new PrivateStorageOperationFailed();
        }

        $cacheObjectId = self::cacheObjectIdForSourceObjectId($sourceObjectId);
        try {
            $cached = $this->storage->read($cacheObjectId, self::MAX_PREVIEW_BYTES);
            if (self::isValidPreview($cached)) {
                return $cached;
            }
        } catch (PrivateStorageOperationFailed) {
            // A cache miss and an unavailable object are deliberately indistinguishable here.
            // Reading the source and writing the regenerated cache below still fail closed.
        }

        $source = $this->storage->read($sourceObjectId, $sourceSize);
        $sourceInfo = self::inspectSource($source, $sourceMimeType, $sourceSize);
        self::assertGdCapabilities($sourceMimeType);
        $decoded = @imagecreatefromstring($source);
        if (!$decoded instanceof \GdImage) {
            throw new PrivateStorageOperationFailed();
        }

        try {
            $preview = imagecreatetruecolor(self::PREVIEW_WIDTH, self::PREVIEW_HEIGHT);
        } catch (\ValueError) {
            unset($decoded);
            throw new PrivateStorageOperationFailed();
        }
        if (!$preview instanceof \GdImage) {
            unset($decoded);
            throw new PrivateStorageOperationFailed();
        }

        try {
            $backgroundColor = imagecolorallocate($preview, 10, 14, 20);
            if (false === $backgroundColor) {
                throw new PrivateStorageOperationFailed();
            }
            imagealphablending($preview, true);
            imagefill($preview, 0, 0, $backgroundColor);

            $sourceWidth = $sourceInfo['width'];
            $sourceHeight = $sourceInfo['height'];
            $sourceRatio = $sourceWidth / $sourceHeight;
            $previewRatio = self::PREVIEW_WIDTH / self::PREVIEW_HEIGHT;

            if ($sourceRatio > $previewRatio) {
                $cropWidth = (int) round($sourceHeight * $previewRatio);
                $sourceX = (int) floor(($sourceWidth - $cropWidth) / 2);
                imagecopyresampled(
                    $preview,
                    $decoded,
                    0,
                    0,
                    $sourceX,
                    0,
                    self::PREVIEW_WIDTH,
                    self::PREVIEW_HEIGHT,
                    $cropWidth,
                    $sourceHeight,
                );
            } else {
                $cropHeight = (int) round($sourceWidth / $previewRatio);
                $sourceY = (int) floor(($sourceHeight - $cropHeight) / 2);
                imagecopyresampled(
                    $preview,
                    $decoded,
                    0,
                    0,
                    0,
                    $sourceY,
                    self::PREVIEW_WIDTH,
                    self::PREVIEW_HEIGHT,
                    $sourceWidth,
                    $cropHeight,
                );
            }

            self::drawPlaySymbol($preview);
            $contents = self::encodePreview($preview);
        } finally {
            unset($decoded, $preview);
        }

        $this->storage->write($cacheObjectId, $contents);

        return $contents;
    }

    /** @return array{width: int, height: int} */
    private static function inspectSource(string $contents, string $expectedMimeType, int $expectedSize): array
    {
        if (strlen($contents) !== $expectedSize || strlen($contents) > self::MAX_SOURCE_BYTES) {
            throw new PrivateStorageOperationFailed();
        }

        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $imageInfo = @getimagesizefromstring($contents);
        if (!is_string($mimeType)
            || !in_array($mimeType, self::SOURCE_MIME_TYPES, true)
            || $mimeType !== $expectedMimeType
            || false === $imageInfo
            || $mimeType !== $imageInfo['mime']
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] > self::MAX_SOURCE_DIMENSION
            || $imageInfo[1] > self::MAX_SOURCE_DIMENSION
            || $imageInfo[0] > intdiv(self::MAX_SOURCE_PIXELS, $imageInfo[1])
        ) {
            throw new PrivateStorageOperationFailed();
        }

        return ['width' => $imageInfo[0], 'height' => $imageInfo[1]];
    }

    private static function isValidPreview(string $contents): bool
    {
        if (strlen($contents) < 1 || strlen($contents) > self::MAX_PREVIEW_BYTES) {
            return false;
        }

        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $imageInfo = @getimagesizefromstring($contents);

        return 'image/jpeg' === $mimeType
            && false !== $imageInfo
            && self::PREVIEW_WIDTH === $imageInfo[0]
            && self::PREVIEW_HEIGHT === $imageInfo[1]
            && 'image/jpeg' === $imageInfo['mime'];
    }

    private static function assertGdCapabilities(string $sourceMimeType): void
    {
        $gdInfo = gd_info();
        $sourceCapability = match ($sourceMimeType) {
            'image/jpeg' => 'JPEG Support',
            'image/png' => 'PNG Support',
            'image/webp' => 'WebP Support',
            default => null,
        };
        if (null === $sourceCapability
            || true !== ($gdInfo[$sourceCapability] ?? false)
            || true !== ($gdInfo['JPEG Support'] ?? false)
        ) {
            throw new PrivateStorageOperationFailed();
        }
    }

    private static function drawPlaySymbol(\GdImage $image): void
    {
        $centerX = intdiv(self::PREVIEW_WIDTH, 2);
        $centerY = intdiv(self::PREVIEW_HEIGHT, 2);
        $background = imagecolorallocatealpha($image, 0, 0, 0, 62);
        $foreground = imagecolorallocate($image, 255, 255, 255);
        if (false === $background
            || false === $foreground
        ) {
            throw new PrivateStorageOperationFailed();
        }
        imagefilledellipse($image, $centerX, $centerY, 132, 132, $background);
        imagefilledpolygon($image, [
            $centerX - 18, $centerY - 30,
            $centerX - 18, $centerY + 30,
            $centerX + 34, $centerY,
        ], $foreground);
    }

    private static function encodePreview(\GdImage $image): string
    {
        foreach ([88, 82, 76, 70] as $quality) {
            ob_start();
            try {
                if (!imagejpeg($image, null, $quality)) {
                    throw new PrivateStorageOperationFailed();
                }
                $contents = ob_get_contents();
            } finally {
                ob_end_clean();
            }

            if (is_string($contents) && strlen($contents) > 0 && strlen($contents) <= self::MAX_PREVIEW_BYTES) {
                return $contents;
            }
        }

        throw new PrivateStorageOperationFailed();
    }

    private static function assertObjectId(string $objectId): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)) {
            throw new PrivateStorageOperationFailed();
        }
    }
}
