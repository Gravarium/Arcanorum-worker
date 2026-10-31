<?php

declare(strict_types=1);

namespace App\Module\Advertising\Http;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdvertisingAssetController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PrivateObjectStorageInterface $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/media/ads/{id}.{extension}',
        name: 'advertising_asset',
        requirements: ['id' => '[a-f0-9]{32}', 'extension' => 'jpg|png|webp|mp4'],
        methods: ['GET'],
    )]
    public function show(string $id, string $extension, Request $request): Response
    {
        $publicPath = '/media/ads/'.$id.'.'.$extension;
        try {
            $asset = $this->connection->fetchAssociative(
                "SELECT asset.storage_object_id, asset.mime_type, asset.size
                 FROM cms_advertising_asset AS asset
                 WHERE asset.id = :id
                   AND EXISTS (
                       SELECT 1
                       FROM cms_advertising_ad AS advertisement
                       JOIN cms_advertising_placement AS placement ON placement.id = advertisement.placement_id
                       WHERE advertisement.status = 'active'
                         AND placement.archived = false
                         AND placement.paused = false
                         AND placement.formats @> jsonb_build_array(advertisement.format)
                         AND (
                             advertisement.creative->>'image_path' = :path
                             OR advertisement.creative->>'video_path' = :path
                             OR advertisement.creative->>'poster_path' = :path
                             OR COALESCE(advertisement.creative->>'html', '') LIKE :needle
                         )
                   )",
                ['id' => $id, 'path' => $publicPath, 'needle' => '%'.$publicPath.'%'],
            );
        } catch (DbalException $exception) {
            $this->logger->error('Anzeigen-Datei konnte nicht zugeordnet werden.', ['failure_type' => $exception::class]);

            return $this->unavailable();
        }

        if (false === $asset) {
            return $this->notFound();
        }

        $objectId = $asset['storage_object_id'] ?? null;
        $mimeType = $asset['mime_type'] ?? null;
        $size = self::sizeColumn($asset['size'] ?? null);
        if (!is_string($objectId)
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
            || !is_string($mimeType)
            || $extension !== self::extensionForMime($mimeType)
            || null === $size
            || $size < 1
            || $size > ('video/mp4' === $mimeType ? 33554432 : 4194304)
        ) {
            $this->logger->error('Anzeigen-Dateimetadaten sind ungültig.', ['asset_id' => $id]);

            return $this->unavailable();
        }

        $range = null;
        $rangeHeader = $request->headers->get('Range');
        if ('video/mp4' === $mimeType && null !== $rangeHeader) {
            $range = self::parseRange($rangeHeader, $size);
            if (null === $range) {
                return new Response('', Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE, [
                    'Accept-Ranges' => 'bytes',
                    'Cache-Control' => 'no-store',
                    'Content-Range' => 'bytes */'.$size,
                    'X-Content-Type-Options' => 'nosniff',
                ]);
            }
        }

        try {
            $contents = $this->storage->read($objectId, $size);
        } catch (PrivateStorageOperationFailed) {
            return $this->unavailable();
        }

        $detectedMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (strlen($contents) !== $size || $detectedMimeType !== $mimeType) {
            $this->logger->error('Anzeigen-Datei stimmt nicht mit ihren gespeicherten Metadaten überein.', ['asset_id' => $id]);

            return $this->unavailable();
        }

        $body = $contents;
        $status = Response::HTTP_OK;
        $headers = [
            'Cache-Control' => 'no-store',
            'Content-Disposition' => 'inline',
            'Content-Type' => $mimeType,
            'Content-Length' => (string) strlen($contents),
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if ('video/mp4' === $mimeType) {
            $headers['Accept-Ranges'] = 'bytes';
        }
        if (null !== $range) {
            [$start, $end] = $range;
            $body = substr($contents, $start, $end - $start + 1);
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
            $headers['Content-Length'] = (string) strlen($body);
            $status = Response::HTTP_PARTIAL_CONTENT;
        }

        return new Response($body, $status, $headers);
    }

    private static function extensionForMime(string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            default => null,
        };
    }

    private static function sizeColumn(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match('/\A[1-9][0-9]*\z/D', $value)) {
            return (int) $value;
        }

        return null;
    }

    /** @return array{int, int}|null */
    private static function parseRange(string $header, int $size): ?array
    {
        if ($size < 1 || strlen($header) > 100 || 1 !== preg_match('/\Abytes=(\d*)-(\d*)\z/D', $header, $matches)) {
            return null;
        }

        $rangeStart = $matches[1];
        $rangeEnd = $matches[2];
        if ('' === $rangeStart && '' === $rangeEnd) {
            return null;
        }
        if ('' === $rangeStart) {
            $suffixLength = (int) $rangeEnd;
            if ($suffixLength < 1) {
                return null;
            }

            return [max(0, $size - $suffixLength), $size - 1];
        }

        $start = (int) $rangeStart;
        if ($start >= $size) {
            return null;
        }
        $end = '' === $rangeEnd ? $size - 1 : min((int) $rangeEnd, $size - 1);
        if ($end < $start) {
            return null;
        }

        return [$start, $end];
    }

    private function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function unavailable(): Response
    {
        return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store', 'Retry-After' => '60', 'X-Content-Type-Options' => 'nosniff']);
    }
}
