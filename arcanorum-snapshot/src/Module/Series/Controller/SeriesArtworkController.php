<?php

declare(strict_types=1);

namespace App\Module\Series\Controller;

use App\Entity\ContentSeries;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use App\Platform\Hosting\PrivateStorageOperationFailed;
use App\Repository\ContentSeriesRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class SeriesArtworkController
{
    private const int MAX_FILE_BYTES = 4194304;

    /** @var list<string> */
    private const array ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

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
    public function show(string $slug, Request $request, ContentSeriesRepository $series): Response
    {
        $item = $series->findPublishedBySlug($slug);
        if (!$item instanceof ContentSeries) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return $this->imageResponse($item, false, $request);
    }

    #[Route(
        '/admin/series/{id}/artwork',
        name: 'admin_series_artwork_preview',
        requirements: ['id' => '[a-f0-9]{32}'],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_ADMIN')]
    public function preview(string $id, Request $request, ContentSeriesRepository $series): Response
    {
        $item = $series->find($id);
        if (!$item instanceof ContentSeries) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        return $this->imageResponse($item, true, $request);
    }

    private function imageResponse(ContentSeries $series, bool $private, Request $request): Response
    {
        $objectId = $series->getArtworkObjectId();
        $expectedMimeType = $series->getArtworkMimeType();
        $expectedSize = $series->getArtworkSize();
        if (null === $objectId
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/', $objectId)
            || null === $expectedMimeType
            || null === $expectedSize
            || !in_array($expectedMimeType, self::ALLOWED_MIME_TYPES, true)
            || $expectedSize < 1
            || $expectedSize > self::MAX_FILE_BYTES
        ) {
            return new Response('', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }

        try {
            $contents = $this->storage->read($objectId, $expectedSize);
        } catch (PrivateStorageOperationFailed) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        $detectedMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($contents);
        if (strlen($contents) !== $expectedSize
            || !is_string($detectedMimeType)
            || $detectedMimeType !== $expectedMimeType
        ) {
            return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, ['Cache-Control' => 'no-store']);
        }

        $response = new Response($contents, Response::HTTP_OK, [
            'Content-Type' => $detectedMimeType,
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => $private ? 'private, no-store' : 'public, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if (!$private) {
            $response->setEtag(hash('sha256', $contents));
            $response->isNotModified($request);
        }

        return $response;
    }
}
