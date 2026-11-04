<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubtitleTrack;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class VideoSubtitleCacheFlowTest extends WebTestCase
{
    public function testPublishedSubtitleRevalidatesAndIsDeniedAfterUnpublish(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tokenStorage = $client->getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(null);
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $storage = $client->getContainer()->get(PrivateObjectStorageInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(PrivateObjectStorageInterface::class, $storage);

        $contents = "WEBVTT\n\n00:00:00.000 --> 00:00:01.500\nPublic caption.\n";
        $objectId = bin2hex(random_bytes(32));
        $video = new Video(
            'Subtitle cache '.bin2hex(random_bytes(4)),
            'Subtitle cache publication fixture.',
            null,
            VideoSourceType::Stream,
            'https://media.example.test/subtitle-cache.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $track = new VideoSubtitleTrack($video, 'en', 'English', $objectId, strlen($contents), hash('sha256', $contents));
        $storage->write($objectId, $contents);
        $entityManager->persist($video);
        $entityManager->persist($track);
        $entityManager->flush();

        try {
            $path = '/videos/'.$video->getId().'/subtitles/'.$track->getId();
            $client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'text/vtt; charset=UTF-8');
            self::assertTrue(
                $client->getResponse()->headers->getCacheControlDirective('private'),
                'Cache-Control value: '.(string) $client->getResponse()->headers->get('Cache-Control'),
            );
            self::assertNull($client->getResponse()->headers->getCacheControlDirective('public'));
            self::assertSame(0, $client->getResponse()->headers->getCacheControlDirective('max-age'));
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('must-revalidate'));
            self::assertSame($contents, $client->getResponse()->getContent());
            $etag = (string) $client->getResponse()->headers->get('ETag');
            self::assertNotSame('', $etag);

            $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_MODIFIED);
            self::assertSame('', $client->getResponse()->getContent());

            $activeEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            self::assertInstanceOf(EntityManagerInterface::class, $activeEntityManager);
            $activeEntityManager->clear();
            $storedVideo = $activeEntityManager->find(Video::class, $video->getId());
            self::assertInstanceOf(Video::class, $storedVideo);
            $storedVideo->setPublishingStatus(PublishingStatus::Draft);
            $activeEntityManager->flush();
            $activeEntityManager->clear();

            $client->request('GET', $path, server: ['HTTP_IF_NONE_MATCH' => $etag]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
        } finally {
            $cleanupEntityManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupEntityManager instanceof EntityManagerInterface) {
                $cleanupEntityManager->getConnection()->executeStatement(
                    'DELETE FROM videos WHERE id = :id',
                    ['id' => $video->getId()],
                );
            }
            $storage->delete($objectId);
        }
    }
}
