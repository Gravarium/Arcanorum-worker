<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class VideoPlayerWatermarkUploadService
{
    private const int MAX_FILE_BYTES = 2097152;
    private const int MAX_DIMENSION = 2048;

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
            throw new \InvalidArgumentException('Das Wasserzeichen konnte nicht sicher gelesen werden.');
        }

        $reportedSize = $upload->getSize();
        if (false === $reportedSize || $reportedSize < 1 || $reportedSize > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Wasserzeichen müssen zwischen 1 Byte und 2 MiB groß sein.');
        }

        $contents = file_get_contents($upload->getPathname(), false, null, 0, self::MAX_FILE_BYTES + 1);
        if (false === $contents || strlen($contents) !== $reportedSize) {
            throw new \InvalidArgumentException('Das Wasserzeichen ist leer, zu groß oder wurde während der Prüfung verändert.');
        }

        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Erlaubt sind ausschließlich JPEG-, PNG- und WebP-Wasserzeichen.');
        }

        $imageInfo = @getimagesizefromstring($contents);
        if (false === $imageInfo
            || $mimeType !== $imageInfo['mime']
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] > self::MAX_DIMENSION
            || $imageInfo[1] > self::MAX_DIMENSION
        ) {
            throw new \InvalidArgumentException('Das Wasserzeichenformat oder seine Abmessungen sind ungültig.');
        }

        $objectId = bin2hex(random_bytes(32));
        $this->storage->write($objectId, $contents);

        return [
            'object_id' => $objectId,
            'mime_type' => $mimeType,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    public function delete(string $objectId): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)) {
            throw new \InvalidArgumentException('Die Kennung des privaten Wasserzeichens ist ungültig.');
        }

        $this->storage->delete($objectId);
    }
}
