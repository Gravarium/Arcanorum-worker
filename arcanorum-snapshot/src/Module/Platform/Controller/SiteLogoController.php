<?php

declare(strict_types=1);

namespace App\Module\Platform\Controller;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Service\SiteSettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class SiteLogoController extends AbstractController
{
    public function __construct(
        private readonly PrivateObjectStorageInterface $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        '/site-logo/{sha256}',
        name: 'site_logo_image',
        requirements: ['sha256' => '[a-f0-9]{64}'],
        methods: ['GET'],
    )]
    public function show(string $sha256, SiteSettingsProvider $settings): Response
    {
        $current = $settings->current();
        $objectId = $current->getSiteLogoObjectId();
        $mimeType = $current->getSiteLogoMimeType();
        $size = $current->getSiteLogoSize();
        $digest = $current->getSiteLogoSha256();
        if (null === $objectId
            || null === $mimeType
            || null === $size
            || null === $digest
            || !hash_equals($digest, $sha256)
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
            || 'image/png' !== $mimeType
            || $size < 1
            || $size > 524288
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $digest)
        ) {
            return $this->notFound();
        }

        try {
            $contents = $this->storage->read($objectId, $size);
        } catch (PrivateStorageOperationFailed $exception) {
            $this->logger->error('Das private Website-Logo ist nicht verfügbar.', [
                'failure_type' => $exception::class,
            ]);

            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $detectedMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        $imageInfo = @getimagesizefromstring($contents);
        if (strlen($contents) !== $size
            || !hash_equals($digest, hash('sha256', $contents))
            || 'image/png' !== $detectedMimeType
            || false === $imageInfo
            || 'image/png' !== $imageInfo['mime']
            || $imageInfo[0] < 1
            || $imageInfo[0] > 466
            || $imageInfo[1] < 1
            || $imageInfo[1] > 160
        ) {
            $this->logger->error('Das private Website-Logo stimmt nicht mit seinen geprüften Metadaten überein.');

            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return new Response($contents, Response::HTTP_OK, [
            'Cache-Control' => 'public, max-age=300',
            'Content-Disposition' => 'inline',
            'Content-Length' => (string) strlen($contents),
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            'Content-Type' => 'image/png',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function notFound(): Response
    {
        return new Response('', Response::HTTP_NOT_FOUND, [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
