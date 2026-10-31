<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class VideoCategoryArtworkUploadService
{
    private const int MAX_UPLOAD_BYTES = 4194304;
    private const int MAX_STORED_BYTES = 1048576;
    private const int MAX_INPUT_DIMENSION = 4096;
    private const int MAX_INPUT_PIXELS = 16000000;
    private const int MAX_WIDTH = 320;
    private const int MAX_HEIGHT = 180;

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
            throw new \InvalidArgumentException('Das Kategorie-Artwork konnte nicht sicher gelesen werden.');
        }

        $reportedSize = $upload->getSize();
        if (false === $reportedSize || $reportedSize < 1 || $reportedSize > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('Kategorie-Artworks dürfen höchstens 4 MiB groß sein.');
        }

        $contents = file_get_contents($upload->getPathname(), false, null, 0, self::MAX_UPLOAD_BYTES + 1);
        if (false === $contents || strlen($contents) !== $reportedSize) {
            throw new \InvalidArgumentException('Das Kategorie-Artwork ist leer, zu groß oder wurde während der Prüfung verändert.');
        }

        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Erlaubt sind ausschließlich JPEG-, PNG- und WebP-Bilder.');
        }

        $imageInfo = @getimagesizefromstring($contents);
        if (false === $imageInfo
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] > self::MAX_INPUT_DIMENSION
            || $imageInfo[1] > self::MAX_INPUT_DIMENSION
            || $imageInfo[0] * $imageInfo[1] > self::MAX_INPUT_PIXELS
            || $imageInfo['mime'] !== $mimeType
        ) {
            throw new \InvalidArgumentException('Bildformat oder Bildabmessungen des Kategorie-Artworks sind ungültig.');
        }

        $normalized = $this->normalizeImage($contents, $mimeType, $imageInfo[0], $imageInfo[1]);
        if (strlen($normalized) < 1 || strlen($normalized) > self::MAX_STORED_BYTES) {
            throw new \InvalidArgumentException('Das verkleinerte Kategorie-Artwork überschreitet die Speichergrenze.');
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

    public function delete(string $objectId): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)) {
            throw new \InvalidArgumentException('Die Kennung des privaten Kategorie-Artworks ist ungültig.');
        }

        $this->storage->delete($objectId);
    }

    private function normalizeImage(string $contents, string $mimeType, int $width, int $height): string
    {
        $source = @imagecreatefromstring($contents);
        if (!$source instanceof \GdImage) {
            throw new \InvalidArgumentException('Das Kategorie-Artwork ist kein lesbares Rasterbild.');
        }

        $scale = min(1.0, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $targetWidth = max(1, (int) floor($width * $scale));
        $targetHeight = max(1, (int) floor($height * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target instanceof \GdImage) {
            unset($source);
            throw new \InvalidArgumentException('Das Kategorie-Artwork konnte nicht verarbeitet werden.');
        }

        if ('image/jpeg' === $mimeType) {
            $background = imagecolorallocate($target, 255, 255, 255);
            if (false === $background) {
                unset($source, $target);
                throw new \InvalidArgumentException('Das Kategorie-Artwork konnte nicht verarbeitet werden.');
            }
            imagefill($target, 0, 0, $background);
        } else {
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            if (false === $transparent) {
                unset($source, $target);
                throw new \InvalidArgumentException('Das Kategorie-Artwork konnte nicht verarbeitet werden.');
            }
            imagefill($target, 0, 0, $transparent);
        }

        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        try {
            $encoded = match ($mimeType) {
                'image/jpeg' => imagejpeg($target, null, 85),
                'image/png' => imagepng($target, null, 6),
                'image/webp' => function_exists('imagewebp') && imagewebp($target, null, 84),
                default => false,
            };
            $normalized = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($source, $target);
        }

        if (!$encoded || !is_string($normalized)) {
            throw new \InvalidArgumentException('Das Kategorie-Artwork konnte nicht sicher neu kodiert werden.');
        }

        return $normalized;
    }
}
