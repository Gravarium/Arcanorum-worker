<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class NewsArticleThumbnailCacheFlowTest extends WebTestCase
{
    public function testPublishedThumbnailRevalidatesBeforeServingAndAfterMembershipChange(): void
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
        $article = new NewsArticle(
            'Thumbnail cache '.$suffix,
            'thumbnail-cache-'.$suffix,
            null,
            'Public thumbnail cache fixture.',
            'Public thumbnail cache body.',
            PublishingStatus::Published,
            false,
            true,
        );
        $objectId = bin2hex(random_bytes(32));
        $article->attachThumbnail($objectId, 'image/png', strlen($contents));

        try {
            $storage->write($objectId, $contents);
            $entityManager->persist($article);
            $entityManager->flush();

            $path = '/news/'.$article->getId().'/thumbnail';
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSame($contents, $client->getResponse()->getContent());
            $cacheControl = $client->getResponse()->headers;
            self::assertTrue(
                true === $cacheControl->getCacheControlDirective('no-store')
                || ('0' === $cacheControl->getCacheControlDirective('max-age')
                    && true === $cacheControl->getCacheControlDirective('must-revalidate')),
            );
            $etag = (string) $client->getResponse()->headers->get('ETag');
            self::assertNotSame('', $etag);

            $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
            self::assertSame('', $client->getResponse()->getContent());

            $activeEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $activeEntityManager);
            $activeEntityManager->getConnection()->executeStatement(
                'UPDATE news_articles SET members_only = TRUE WHERE id = :id',
                ['id' => $article->getId()],
            );
            $activeEntityManager->clear();

            $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
        } finally {
            $cleanupEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupEntityManager instanceof EntityManagerInterface) {
                $cleanupEntityManager->getConnection()->executeStatement(
                    'DELETE FROM news_articles WHERE id = :id',
                    ['id' => $article->getId()],
                );
            }
            $storage->delete($objectId);
        }
    }
}
