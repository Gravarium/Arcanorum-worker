<?php

declare(strict_types=1);

namespace App\Module\Advertising\Infrastructure;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class AdvertisingAssetUploadService
{
    private const int MAXIMUM_IMAGE_BYTES = 4194304;
    private const int MAXIMUM_VIDEO_BYTES = 33554432;
    private const int MAXIMUM_IMAGE_DIMENSION = 4096;

    public function __construct(
        private PrivateObjectStorageInterface $storage,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function storeImage(UploadedFile $upload): string
    {
        return $this->store($upload, 'image');
    }

    public function storeVideo(UploadedFile $upload): string
    {
        return $this->store($upload, 'video');
    }

    public function deleteIfUnreferenced(string $publicPath): void
    {
        if (1 !== preg_match('/\A\/media\/ads\/([a-f0-9]{32})\.(?:jpg|png|webp|mp4)\z/D', $publicPath, $matches)) {
            return;
        }

        $asset = $this->connection->fetchAssociative(
            'SELECT storage_object_id FROM cms_advertising_asset WHERE id = :id',
            ['id' => $matches[1]],
        );
        if (false === $asset) {
            return;
        }

        $inUse = $this->connection->fetchOne(
            "SELECT EXISTS (
                SELECT 1 FROM cms_advertising_ad
                WHERE creative->>'image_path' = :path
                   OR creative->>'video_path' = :path
                   OR creative->>'poster_path' = :path
                   OR COALESCE(creative->>'html', '') LIKE :needle
            )",
            ['path' => $publicPath, 'needle' => '%'.$publicPath.'%'],
        );
        if (in_array($inUse, [true, 1, '1', 't', 'true'], true)) {
            return;
        }

        $objectId = $asset['storage_object_id'] ?? null;
        if (!is_string($objectId) || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)) {
            throw new \RuntimeException('Stored advertising asset metadata is invalid.');
        }

        $this->storage->delete($objectId);
        $this->connection->delete('cms_advertising_asset', ['id' => $matches[1]]);
    }

    private function store(UploadedFile $upload, string $kind): string
    {
        if (!in_array($kind, ['image', 'video'], true) || !$upload->isValid()) {
            throw new \InvalidArgumentException('Die Anzeigen-Datei konnte nicht sicher gelesen werden.');
        }

        $maximumBytes = 'image' === $kind ? self::MAXIMUM_IMAGE_BYTES : self::MAXIMUM_VIDEO_BYTES;
        $reportedSize = $upload->getSize();
        if (false === $reportedSize || $reportedSize < 1 || $reportedSize > $maximumBytes) {
            throw new \InvalidArgumentException('Die Anzeigen-Datei ist leer oder überschreitet die erlaubte Größe.');
        }

        $contents = file_get_contents($upload->getPathname(), false, null, 0, $maximumBytes + 1);
        if (false === $contents || strlen($contents) !== $reportedSize) {
            throw new \InvalidArgumentException('Die Anzeigen-Datei ist leer, zu groß oder wurde während der Prüfung verändert.');
        }

        $mimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (!is_string($mimeType)) {
            throw new \InvalidArgumentException('Der Dateityp der Anzeige konnte nicht geprüft werden.');
        }

        if ('image' === $kind) {
            $this->assertSafeImage($contents, $mimeType);
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => throw new \InvalidArgumentException('Erlaubt sind JPEG-, PNG- und WebP-Bilder.'),
            };
        } else {
            if ('video/mp4' !== $mimeType || 'ftyp' !== substr($contents, 4, 4)) {
                throw new \InvalidArgumentException('Erlaubt sind ausschließlich gültige MP4-Videodateien.');
            }
            $extension = 'mp4';
        }

        $assetId = bin2hex(random_bytes(16));
        $objectId = bin2hex(random_bytes(32));
        $publicPath = '/media/ads/'.$assetId.'.'.$extension;
        $this->storage->write($objectId, $contents);

        try {
            $this->connection->insert('cms_advertising_asset', [
                'id' => $assetId,
                'storage_object_id' => $objectId,
                'kind' => $kind,
                'mime_type' => $mimeType,
                'size' => $reportedSize,
                'created_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP'),
            ]);
        } catch (\Throwable $exception) {
            try {
                $this->storage->delete($objectId);
            } catch (\Throwable $cleanupException) {
                $this->logger->error('Nicht verknüpfte Anzeigen-Datei konnte nach einem Datenbankfehler nicht entfernt werden.', [
                    'failure_type' => $cleanupException::class,
                ]);
            }

            throw $exception;
        }

        return $publicPath;
    }

    private function assertSafeImage(string $contents, string $mimeType): void
    {
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \InvalidArgumentException('Erlaubt sind JPEG-, PNG- und WebP-Bilder.');
        }

        $imageInfo = @getimagesizefromstring($contents);
        if (false === $imageInfo
            || $mimeType !== $imageInfo['mime']
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] > self::MAXIMUM_IMAGE_DIMENSION
            || $imageInfo[1] > self::MAXIMUM_IMAGE_DIMENSION
        ) {
            throw new \InvalidArgumentException('Das Bildformat oder die Bildabmessungen sind ungültig.');
        }
    }
}
