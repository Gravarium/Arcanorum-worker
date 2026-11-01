<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class VideoCategoryArtworkCacheFlowTest extends WebTestCase
{
    public function testCategoryArtworkRevalidatesAfterReplacementIsRemoved(): void
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
        $category = new ContentCategory('Artwork cache '.$suffix, ContentCategoryType::Video);
        $category->updateTaxonomy('artwork-cache-'.$suffix, '', null, null, null, 0);
        $objectId = bin2hex(random_bytes(32));
        $category->attachArtwork($objectId, 'image/png', strlen($contents), hash('sha256', $contents));

        try {
            $storage->write($objectId, $contents);
            $entityManager->persist($category);
            $entityManager->flush();

            $path = '/videos/category/'.$category->getSlug().'/artwork';
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/png');
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
            $storedCategory = $activeEntityManager->find(ContentCategory::class, $category->getId());
            self::assertInstanceOf(ContentCategory::class, $storedCategory);
            $storedCategory->removeArtwork();
            $activeEntityManager->flush();
            $activeEntityManager->clear();

            $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
        } finally {
            $cleanupEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupEntityManager instanceof EntityManagerInterface) {
                $cleanupEntityManager->getConnection()->executeStatement(
                    'DELETE FROM content_categories WHERE id = :id',
                    ['id' => $category->getId()],
                );
            }
            $storage->delete($objectId);
        }
    }
}
