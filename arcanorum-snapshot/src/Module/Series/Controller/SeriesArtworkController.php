<?php

declare(strict_types=1);

namespace App\Module\Series\Controller;

use App\Entity\ContentSeries;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentSeriesRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class SeriesArtworkController
{
    public function __construct(private PrivateObjectStorageInterface $storage)
    {
    }

    #[Route(
        '/series/{slug}/artwork',
        name: 'series_artwork',
        requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['GET'],
        stateless: true,
    )]
    public function show(string $slug, ContentSeriesRepository $series): Response
    {
        $item = $series->findPublishedBySlug($slug);
        if (!$item instanceof ContentSeries) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return $this->imageResponse($item, false);
    }

    #[Route(
        '/admin/series/{id}/artwork',
        name: 'admin_series_artwork_preview',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_ADMIN')]
    public function preview(string $id, ContentSeriesRepository $series): Response
    {
        $item = $series->find($id);
        if (!$item instanceof ContentSeries) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return $this->imageResponse($item, true);
    }

    private function imageResponse(ContentSeries $series, bool $private): Response
    {
        $objectId = $series->getArtworkObjectId();
        $expectedMimeType = $series->getArtworkMimeType();
        $expectedSize = $series->getArtworkSize();
        if (null === $objectId || null === $expectedMimeType || null === $expectedSize) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        try {
            $contents = $this->storage->read($objectId);
        } catch (PrivateStorageOperationFailed) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        $detectedMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (strlen($contents) !== $expectedSize
            || !is_string($detectedMimeType)
            || $detectedMimeType !== $expectedMimeType
            || !in_array($detectedMimeType, ['image/jpeg', 'image/png', 'image/webp'], true)
        ) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        return new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $detectedMimeType,
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => $private ? 'private, no-store' : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
