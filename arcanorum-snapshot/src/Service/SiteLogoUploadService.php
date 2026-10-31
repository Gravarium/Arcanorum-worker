<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class SiteLogoUploadService
{
    private const int MAX_UPLOAD_BYTES = 4194304;
    private const int MAX_SOURCE_DIMENSION = 2048;
    private const int MAX_SOURCE_PIXELS = 4000000;
    private const int OUTPUT_WIDTH = 466;
    private const int OUTPUT_HEIGHT = 160;
    private const int MAX_OUTPUT_BYTES = 524288;

    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private PrivateObjectStorageInterface $storage)
    {
    }

    /**
     * @return array{object_id: string, mime_type: string, size: int, sha256: string}
     */
    public function store(UploadedFile $upload): array
    {
        if (!$upload->isValid()) {
            throw new \InvalidArgumentException('Das Logo konnte nicht sicher gelesen werden.');
        }

        $reportedSize = $upload->getSize();
        if (false === $reportedSize || $reportedSize < 1 || $reportedSize > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('Das Logo muss zwischen 1 Byte und 4 MiB groß sein.');
        }

        $contents = file_get_contents($upload->getPathname(), false, null, 0, self::MAX_UPLOAD_BYTES + 1);
        if (false === $contents || strlen($contents) !== $reportedSize) {
            throw new \InvalidArgumentException('Das Logo ist leer, zu groß oder wurde während der Prüfung verändert.');
        }

        return $this->storeContents($contents);
    }

    /**
     * @return array{object_id: string, mime_type: string, size: int, sha256: string}
     */
    public function storeContents(string $contents): array
    {
        $size = strlen($contents);
        if ($size < 1 || $size > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('Das Logo muss zwischen 1 Byte und 4 MiB groß sein.');
        }

        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Erlaubt sind ausschließlich JPEG-, PNG- und WebP-Logos.');
        }

        $imageInfo = @getimagesizefromstring($contents);
        if (false === $imageInfo
            || $mimeType !== $imageInfo['mime']
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] > self::MAX_SOURCE_DIMENSION
            || $imageInfo[1] > self::MAX_SOURCE_DIMENSION
            || $imageInfo[0] * $imageInfo[1] > self::MAX_SOURCE_PIXELS
        ) {
            throw new \InvalidArgumentException('Das Logoformat oder seine Abmessungen sind ungültig.');
        }

        $source = @imagecreatefromstring($contents);
        if (!$source instanceof \GdImage) {
            throw new \InvalidArgumentException('Das Logo kann nicht sicher verarbeitet werden.');
        }

        $canvas = imagecreatetruecolor(self::OUTPUT_WIDTH, self::OUTPUT_HEIGHT);
        if (!$canvas instanceof \GdImage) {
            unset($source);
            throw new \InvalidArgumentException('Das Logo kann nicht sicher verarbeitet werden.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        if (false === $transparent) {
            unset($source, $canvas);
            throw new \InvalidArgumentException('Das Logo kann nicht sicher verarbeitet werden.');
        }
        imagefill($canvas, 0, 0, $transparent);

        $scale = min(self::OUTPUT_WIDTH / $imageInfo[0], self::OUTPUT_HEIGHT / $imageInfo[1], 1.0);
        $targetWidth = max(1, (int) round($imageInfo[0] * $scale));
        $targetHeight = max(1, (int) round($imageInfo[1] * $scale));
        $targetX = intdiv(self::OUTPUT_WIDTH - $targetWidth, 2);
        $targetY = intdiv(self::OUTPUT_HEIGHT - $targetHeight, 2);

        imagecopyresampled(
            $canvas,
            $source,
            $targetX,
            $targetY,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $imageInfo[0],
            $imageInfo[1],
        );
        unset($source);
        if (!ob_start()) {
            unset($canvas);
            throw new \InvalidArgumentException('Das Logo kann nicht sicher verarbeitet werden.');
        }
        try {
            imagepng($canvas, null, 9);
            $normalized = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($canvas);
        }

        if (!is_string($normalized) || strlen($normalized) < 1 || strlen($normalized) > self::MAX_OUTPUT_BYTES) {
            throw new \InvalidArgumentException('Das normalisierte Logo ist ungültig oder zu groß.');
        }

        $normalizedInfo = @getimagesizefromstring($normalized);
        if (false === $normalizedInfo
            || 'image/png' !== $normalizedInfo['mime']
            || self::OUTPUT_WIDTH !== $normalizedInfo[0]
            || self::OUTPUT_HEIGHT !== $normalizedInfo[1]
        ) {
            throw new \InvalidArgumentException('Das normalisierte Logo ist ungültig oder zu groß.');
        }

        $objectId = bin2hex(random_bytes(32));
        $this->storage->write($objectId, $normalized);

        return [
            'object_id' => $objectId,
            'mime_type' => 'image/png',
            'size' => strlen($normalized),
            'sha256' => hash('sha256', $normalized),
        ];
    }

    public function delete(string $objectId): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)) {
            throw new \InvalidArgumentException('Die Kennung des privaten Logos ist ungültig.');
        }

        $this->storage->delete($objectId);
    }
}
