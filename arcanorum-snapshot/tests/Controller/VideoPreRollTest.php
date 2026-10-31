<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Advertising\Application\AdvertisingDelivery;
use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Domain\AdStatus;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;
use App\Module\Advertising\Infrastructure\DoctrineAdvertisingStore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VideoPreRollTest extends WebTestCase
{
    public function testActivePrerollKeepsNoJavaScriptFallbackAndDoesNotAddAdsToProviderEmbeds(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $preRollCreated = false;
        $advertisementId = bin2hex(random_bytes(16));

        $suffix = bin2hex(random_bytes(8));
        $category = new ContentCategory('Pre-roll '.$suffix, ContentCategoryType::Video);
        $fileVideo = new Video(
            'Pre-roll file '.$suffix,
            'Native local media used to verify pre-roll gating.',
            $category->getName(),
            VideoSourceType::File,
            '',
            PublishingStatus::Published,
            false,
            true,
        );
        $fileVideo->attachUploadedMedia(bin2hex(random_bytes(32)), 'video/mp4', 4096);
        $embeddedVideo = new Video(
            'Pre-roll embed '.$suffix,
            'Provider playback must not be controlled by the local pre-roll.',
            $category->getName(),
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            PublishingStatus::Published,
            false,
            true,
        );
        $embeddedVideo->setEmbedAllowedOrigins(['https://publisher.example.org']);

        try {
            self::assertFalse((bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM cms_advertising_placement WHERE id = :id)', ['id' => Placement::VIDEO_PREROLL_ID]));
            $entityManager->persist($category);
            $entityManager->persist($fileVideo);
            $entityManager->persist($embeddedVideo);
            $entityManager->flush();

            $store = new DoctrineAdvertisingStore($connection);
            $placement = new Placement(Placement::VIDEO_PREROLL_ID, 'Video pre-roll '.$suffix, [AdFormat::Video]);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $store->savePlacement($placement);
            $preRollCreated = true;
            $store->saveAdvertisement(new Advertisement(
                $advertisementId,
                'Video pre-roll '.$suffix,
                $placement->id,
                AdFormat::Video,
                ['provider' => 'local', 'video_path' => '/media/pre-roll.mp4', 'poster_path' => '/media/pre-roll.webp', 'caption' => 'Werbung'],
                'https://example.invalid/campaign',
                AdStatus::Active,
                $now,
                $now,
            ));
            self::assertCount(1, $store->listAdvertisementsByStatus($placement->id, AdStatus::Active));
            $connection->commit();

            $delivery = $client->getContainer()->get(AdvertisingDelivery::class);
            self::assertInstanceOf(AdvertisingDelivery::class, $delivery);
            self::assertStringContainsString('data-video-preroll-ad', $delivery->renderPlacement($placement->id, $now));

            $client->request('GET', '/videos/'.$fileVideo->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-video-preroll-container] video[data-video-preroll-ad][controls]');
            self::assertSelectorExists('video[data-video-preroll-content][controls]');
            self::assertSelectorNotExists('video[data-video-preroll-content][data-video-preroll-locked]');
            self::assertSelectorNotExists('video[data-video-preroll-content][autoplay]');
            self::assertSelectorExists('[data-video-preroll-status][role="status"][hidden]');
            self::assertStringContainsString('video/video-preroll.js', (string) $client->getResponse()->getContent());

            $client->request('GET', '/videos/'.$embeddedVideo->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-provider-embed][data-testid="video-embed-provider"] button[data-provider-embed-load]');
            self::assertSelectorNotExists('iframe[data-testid="video-embed-provider"]');
            self::assertStringContainsString('video/provider-embed-consent.js', (string) $client->getResponse()->getContent());
            self::assertSelectorNotExists('[data-video-preroll-container]');
        } finally {
            $entityManager->clear();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($preRollCreated) {
                $connection->beginTransaction();
                $connection->executeStatement('DELETE FROM cms_advertising_daily_stats WHERE advertisement_id = :id', ['id' => $advertisementId]);
                $connection->executeStatement('DELETE FROM cms_advertising_ad WHERE id = :id', ['id' => $advertisementId]);
                $connection->executeStatement('DELETE FROM cms_advertising_placement WHERE id = :id', ['id' => Placement::VIDEO_PREROLL_ID]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $fileVideo->getId()]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $embeddedVideo->getId()]);
                $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $category->getId()]);
                $connection->commit();
            }
        }
    }
}
