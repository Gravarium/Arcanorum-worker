<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class VideoThumbnailUploadService
{
    private const int MAX_FILE_BYTES = 4194304;
    private const int MAX_DIMENSION = 4096;

    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(private PrivateObjectStorageInterface $storage)
    {
    }

    /**
     * @return array{object_id: string, mime_type: string, size: int}
     */
    public function store(UploadedFile $upload): array
    {
        if (!$upload->isValid()) {
            throw new \InvalidArgumentException('Das Vorschaubild konnte nicht sicher gelesen werden.');
        }

        $reportedSize = $upload->getSize();
        if (false === $reportedSize || $reportedSize < 1 || $reportedSize > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Vorschaubilder müssen zwischen 1 Byte und 4 MiB groß sein.');
        }

        $contents = file_get_contents($upload->getPathname(), false, null, 0, self::MAX_FILE_BYTES + 1);
        if (false === $contents) {
            throw new \InvalidArgumentException('Das Vorschaubild konnte nicht sicher gelesen werden.');
        }

        if (strlen($contents) !== $reportedSize) {
            throw new \InvalidArgumentException('Das Vorschaubild ist leer, zu groß oder wurde während der Prüfung verändert.');
        }

        return $this->storeContents($contents);
    }

    /**
     * @return array{object_id: string, mime_type: string, size: int}
     */
    public function storeContents(string $contents): array
    {
        $size = strlen($contents);
        if ($size < 1 || $size > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Vorschaubilder müssen zwischen 1 Byte und 4 MiB groß sein.');
        }

        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Erlaubt sind ausschließlich JPEG-, PNG- und WebP-Bilder.');
        }

        $imageInfo = @getimagesizefromstring($contents);
        if (false === $imageInfo
            || $mimeType !== $imageInfo['mime']
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] > self::MAX_DIMENSION
            || $imageInfo[1] > self::MAX_DIMENSION
        ) {
            throw new \InvalidArgumentException('Das Bildformat oder die Bildabmessungen sind ungültig.');
        }

        $objectId = bin2hex(random_bytes(32));
        $this->storage->write($objectId, $contents);

        return ['object_id' => $objectId, 'mime_type' => $mimeType, 'size' => $size];
    }

    public function delete(string $objectId): void
    {
        if (1 !== preg_match('/\\A[a-f0-9]{64}\\z/', $objectId)) {
            throw new \InvalidArgumentException('Die Kennung des privaten Vorschaubilds ist ungültig.');
        }

        $this->storage->delete(VideoSocialPreviewGenerator::cacheObjectIdForSourceObjectId($objectId));
        $this->storage->delete($objectId);
    }
}
