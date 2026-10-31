<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class ContentInlineImageUploadService
{
    private const int MAX_FILE_BYTES = 4_194_304;
    private const int MAX_DIMENSION = 4096;
    private const int MAX_PIXELS = 8_388_608;

    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private PrivateObjectStorageInterface $storage)
    {
    }

    /** @return array{object_id: string, mime_type: string, size: int, sha256: string} */
    public function store(UploadedFile $upload): array
    {
        if (!$upload->isValid()) {
            throw new \InvalidArgumentException('Das Inline-Bild konnte nicht sicher gelesen werden.');
        }

        $reportedSize = $upload->getSize();
        if (false === $reportedSize || $reportedSize < 1 || $reportedSize > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Inline-Bilder müssen zwischen 1 Byte und 4 MiB groß sein.');
        }

        $contents = file_get_contents($upload->getPathname(), false, null, 0, self::MAX_FILE_BYTES + 1);
        if (false === $contents || strlen($contents) !== $reportedSize) {
            throw new \InvalidArgumentException('Das Inline-Bild ist leer, zu groß oder wurde während der Prüfung verändert.');
        }

        return $this->storeContents($contents);
    }

    /** @return array{object_id: string, mime_type: string, size: int, sha256: string} */
    public function storeContents(string $contents): array
    {
        $size = strlen($contents);
        if ($size < 1 || $size > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Inline-Bilder müssen zwischen 1 Byte und 4 MiB groß sein.');
        }

        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $imageInfo = @getimagesizefromstring($contents);
        if (!is_string($mimeType)
            || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)
            || false === $imageInfo
            || $mimeType !== $imageInfo['mime']
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] > self::MAX_DIMENSION
            || $imageInfo[1] > self::MAX_DIMENSION
            || $imageInfo[0] * $imageInfo[1] > self::MAX_PIXELS
        ) {
            throw new \InvalidArgumentException('Erlaubt sind gültige JPEG-, PNG- und WebP-Bilder bis 4096 Pixel je Seite und höchstens 8,4 Megapixel.');
        }

        $decoded = @imagecreatefromstring($contents);
        if (false === $decoded) {
            throw new \InvalidArgumentException('Das Bild konnte nicht sicher dekodiert werden.');
        }

        $normalized = $this->reencode($decoded, $mimeType);
        if (strlen($normalized) < 1 || strlen($normalized) > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Das bereinigte Inline-Bild überschreitet 4 MiB.');
        }

        $objectId = bin2hex(random_bytes(32));
        $this->storage->write($objectId, $normalized);

        return [
            'object_id' => $objectId,
            'mime_type' => $mimeType,
            'size' => strlen($normalized),
            'sha256' => hash('sha256', $normalized),
        ];
    }

    private function reencode(\GdImage $image, string $mimeType): string
    {
        if ('image/png' === $mimeType || 'image/webp' === $mimeType) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();
        try {
            $saved = match ($mimeType) {
                'image/jpeg' => @imagejpeg($image, null, 86),
                'image/png' => @imagepng($image, null, 6),
                'image/webp' => function_exists('imagewebp') && @imagewebp($image, null, 86),
                default => false,
            };
            $normalized = ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw new \InvalidArgumentException('Das Bild konnte nicht bereinigt gespeichert werden.', previous: $exception);
        }

        if (false === $saved || !is_string($normalized)) {
            throw new \InvalidArgumentException('Das Bild konnte nicht bereinigt gespeichert werden.');
        }

        return $normalized;
    }
}
