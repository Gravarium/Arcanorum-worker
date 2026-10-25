<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class VideoMediaUploadService
{
    private const int MAX_FILE_BYTES = 33554432;

    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['video/mp4', 'video/webm', 'video/ogg'];

    public function __construct(private PrivateObjectStorageInterface $storage)
    {
    }

    /**
     * @return array{object_id: string, mime_type: string, size: int}
     */
    public function store(UploadedFile $upload): array
    {
        if (!$upload->isValid()) {
            throw new \InvalidArgumentException('Die Videodatei konnte nicht sicher gelesen werden.');
        }

        $reportedSize = $upload->getSize();
        if (false === $reportedSize || $reportedSize < 1 || $reportedSize > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Videodateien müssen zwischen 1 Byte und 32 MiB groß sein.');
        }

        $contents = file_get_contents(
            $upload->getPathname(),
            false,
            null,
            0,
            self::MAX_FILE_BYTES + 1,
        );
        if (false === $contents) {
            throw new \InvalidArgumentException('Die Videodatei konnte nicht sicher gelesen werden.');
        }

        $size = strlen($contents);
        if ($size !== $reportedSize) {
            throw new \InvalidArgumentException('Die Videodatei ist leer, zu groß oder wurde während der Prüfung verändert.');
        }

        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (!is_string($mimeType) || !in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException('Erlaubt sind ausschließlich MP4-, WebM- und Ogg-Videodateien.');
        }

        $objectId = bin2hex(random_bytes(32));
        $this->storage->write($objectId, $contents);

        return [
            'object_id' => $objectId,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    public function delete(string $objectId): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/', $objectId)) {
            throw new \InvalidArgumentException('Die Kennung der privaten Videodatei ist ungültig.');
        }

        $this->storage->delete($objectId);
    }
}
