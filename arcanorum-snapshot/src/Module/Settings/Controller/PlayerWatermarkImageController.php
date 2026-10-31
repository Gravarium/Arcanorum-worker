<?php

declare(strict_types=1);

namespace App\Module\Settings\Controller;

use App\Entity\SiteSettings;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Service\SiteSettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class PlayerWatermarkImageController extends AbstractController
{
    public function __construct(
        private readonly PrivateObjectStorageInterface $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/_assets/player-watermark/{sha256}',
        name: 'player_watermark_image',
        requirements: ['sha256' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function show(string $sha256, SiteSettingsProvider $settings): Response
    {
        $current = $settings->current();
        if (!$current->isVideoPlayerWatermarkEnabled()) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return $this->imageResponse($current, $sha256, false);
    }

    #[Route(
        '/admin/settings/content/watermark-image/{sha256}',
        name: 'admin_content_settings_watermark_preview',
        requirements: ['sha256' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_ADMIN')]
    public function preview(string $sha256, SiteSettingsProvider $settings): Response
    {
        return $this->imageResponse($settings->current(), $sha256, true);
    }

    private function imageResponse(SiteSettings $settings, string $requestedSha256, bool $private): Response
    {
        $objectId = $settings->getVideoPlayerWatermarkObjectId();
        $mimeType = $settings->getVideoPlayerWatermarkMimeType();
        $size = $settings->getVideoPlayerWatermarkSize();
        $sha256 = $settings->getVideoPlayerWatermarkSha256();
        if (null === $objectId
            || null === $mimeType
            || null === $size
            || null === $sha256
            || !hash_equals($sha256, $requestedSha256)
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
            || !in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
            || $size < 1
            || $size > 2097152
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $sha256)
        ) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        try {
            $contents = $this->storage->read($objectId, $size);
        } catch (PrivateStorageOperationFailed $exception) {
            $this->logger->error('Privates Player-Wasserzeichen ist nicht verfügbar.', ['failure_type' => $exception::class]);

            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        $detectedMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $imageInfo = @getimagesizefromstring($contents);
        if (strlen($contents) !== $size
            || !hash_equals($sha256, hash('sha256', $contents))
            || !is_string($detectedMimeType)
            || $detectedMimeType !== $mimeType
            || false === $imageInfo
            || $mimeType !== $imageInfo['mime']
            || $imageInfo[0] < 1
            || $imageInfo[1] < 1
            || $imageInfo[0] > 2048
            || $imageInfo[1] > 2048
        ) {
            $this->logger->error('Privates Player-Wasserzeichen stimmt nicht mit seinen erlaubten Metadaten überein.');

            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new Response($contents, Response::HTTP_OK, [
            'Cache-Control' => $private ? 'private, no-store' : 'public, max-age=300',
            'Content-Disposition' => 'inline',
            'Content-Length' => (string) strlen($contents),
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Content-Type' => $mimeType,
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
