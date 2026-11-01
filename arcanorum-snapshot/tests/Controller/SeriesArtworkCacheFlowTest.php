<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentSeries;
use App\Entity\PublishingStatus;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class SeriesArtworkCacheFlowTest extends WebTestCase
{
    public function testPublishedArtworkRejectsOutOfRangeImageBeforeMatchingEtagCanReturnNotModified(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $storage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $storage);

        $suffix = bin2hex(random_bytes(6));
        $contents = $this->createPng(4097, 1);
        $imageInfo = getimagesizefromstring($contents);
        self::assertIsArray($imageInfo);
        self::assertSame(4097, $imageInfo[0]);
        $series = new ContentSeries(
            'Out-of-range artwork '.$suffix,
            'out-of-range-artwork-'.$suffix,
            'A stored image outside the upload dimension limit must not be served.',
            PublishingStatus::Published,
        );
        $objectId = bin2hex(random_bytes(32));
        $series->attachArtwork($objectId, 'image/png', strlen($contents));

        try {
            $storage->write($objectId, $contents);
            $entityManager->persist($series);
            $entityManager->flush();

            $client->request(
                'GET',
                '/series/'.$series->getSlug().'/artwork',
                server: ['HTTP_IF_NONE_MATCH' => '"'.hash('sha256', $contents).'"'],
            );

            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
            self::assertFalse($client->getResponse()->headers->has('ETag'));
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM content_series WHERE id = :id',
                ['id' => $series->getId()],
            );
            $storage->delete($objectId);
        }
    }

    public function testPublishedArtworkRevalidatesAndIsDeniedAfterUnpublish(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $storage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $storage);

        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($contents);
        $suffix = bin2hex(random_bytes(6));
        $series = new ContentSeries(
            'Artwork cache '.$suffix,
            'artwork-cache-'.$suffix,
            'Public series artwork cache fixture.',
            PublishingStatus::Published,
        );
        $objectId = bin2hex(random_bytes(32));
        $series->attachArtwork($objectId, 'image/png', strlen($contents));

        try {
            $storage->write($objectId, $contents);
            $entityManager->persist($series);
            $entityManager->flush();

            $path = '/series/'.$series->getSlug().'/artwork';
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSame($contents, $client->getResponse()->getContent());
            $cacheControl = $client->getResponse()->headers;
            self::assertTrue($cacheControl->getCacheControlDirective('public'));
            self::assertSame('0', $cacheControl->getCacheControlDirective('max-age'));
            self::assertTrue($cacheControl->getCacheControlDirective('must-revalidate'));
            $etag = (string) $client->getResponse()->headers->get('ETag');
            self::assertNotSame('', $etag);

            $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
            self::assertSame('', $client->getResponse()->getContent());

            $activeEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $activeEntityManager);
            $activeEntityManager->clear();
            $storedSeries = $activeEntityManager->find(ContentSeries::class, $series->getId());
            self::assertInstanceOf(ContentSeries::class, $storedSeries);
            $storedSeries->revise(
                $storedSeries->getTitle(),
                $storedSeries->getSlug(),
                $storedSeries->getDescription(),
                PublishingStatus::Draft,
            );
            $activeEntityManager->flush();
            $activeEntityManager->clear();

            $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
        } finally {
            $cleanupEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupEntityManager instanceof EntityManagerInterface) {
                $cleanupEntityManager->getConnection()->executeStatement(
                    'DELETE FROM content_series WHERE id = :id',
                    ['id' => $series->getId()],
                );
            }
            $storage->delete($objectId);
        }
    }

    private function createPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefill($image, 0, 0, 0x1A70B1);
        ob_start();
        try {
            self::assertTrue(imagepng($image));
            $contents = ob_get_contents();
        } finally {
            ob_end_clean();
            unset($image);
        }

        self::assertIsString($contents);

        return $contents;
    }
}
