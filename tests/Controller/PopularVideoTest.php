<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PopularVideoTest extends WebTestCase
{
    public function testPublishedViewsAreCountedOncePerSessionAndSortedWithoutDrafts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $popular = new Video(
            'Popular '.$suffix,
            'Aufrufzähler',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-popular.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $unseen = new Video(
            'Unseen '.$suffix,
            'Noch keine Aufrufe',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-unseen.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $draft = new Video(
            'Draft '.$suffix,
            'Nicht öffentlich',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-draft.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );

        try {
            $entityManager->persist($popular);
            $entityManager->persist($unseen);
            $entityManager->persist($draft);
            $entityManager->flush();

            $client->request('GET', '/videos/'.$draft->getId());
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/videos/'.$popular->getId());
            self::assertResponseIsSuccessful();
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringContainsString('no-store', $cacheControl);
            $client->request('GET', '/videos/'.$popular->getId());
            self::assertResponseIsSuccessful();

            $connection = $entityManager->getConnection();
            $popularCount = $connection->fetchOne('SELECT view_count FROM videos WHERE id = :id', ['id' => $popular->getId()]);
            $draftCount = $connection->fetchOne('SELECT view_count FROM videos WHERE id = :id', ['id' => $draft->getId()]);
            self::assertTrue(1 === $popularCount || '1' === $popularCount);
            self::assertTrue(0 === $draftCount || '0' === $draftCount);

            $client->request('GET', '/videos/popular');
            self::assertResponseIsSuccessful();
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertSelectorTextContains('h1', 'Beliebte Videos');
            $html = (string) $client->getResponse()->getContent();
            self::assertMatchesRegularExpression('/Popular '.$suffix.'.*Unseen '.$suffix.'/s', $html);
            self::assertStringNotContainsString('Draft '.$suffix, $html);
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM videos WHERE id IN (:popular, :unseen, :draft)',
                ['popular' => $popular->getId(), 'unseen' => $unseen->getId(), 'draft' => $draft->getId()],
            );
        }
    }
}
