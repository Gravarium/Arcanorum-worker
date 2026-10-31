<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Infrastructure;

use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Domain\AdStatus;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;
use App\Module\Advertising\Infrastructure\DoctrineAdvertisingStore;
use App\Module\Advertising\Infrastructure\DoctrineStatisticsStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineStoresTest extends KernelTestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->createTemporarySchema($connection);
    }

    public function testAdvertisingRecordsRoundTripThroughDoctrineDbal(): void
    {
        $store = new DoctrineAdvertisingStore($this->connection());
        $placement = new Placement('header', 'Header', [AdFormat::HtmlBanner]);
        $createdAt = new \DateTimeImmutable('2026-10-22T12:00:00+00:00');
        $ad = new Advertisement(
            str_repeat('a', 32),
            'Banner',
            $placement->id,
            AdFormat::HtmlBanner,
            ['html' => '<strong>Banner</strong>'],
            'https://example.invalid/campaign',
            AdStatus::Draft,
            $createdAt,
            $createdAt,
        );

        $store->savePlacement($placement);
        $store->saveAdvertisement($ad);

        self::assertSame(AdFormat::HtmlBanner, $store->findPlacement('header')?->formats[0]);
        $loaded = $store->findAdvertisement($ad->id);
        self::assertInstanceOf(Advertisement::class, $loaded);
        self::assertSame($ad->id, $loaded->id);
        self::assertSame(['html' => '<strong>Banner</strong>'], $loaded->creative);
        self::assertCount(1, $store->listAdvertisements('header'));

        $store->savePlacement($placement->pause());
        self::assertTrue($store->findPlacement('header')?->paused);
    }

    public function testDatabaseRejectsInvalidAndDuplicateActivePreRollCreatives(): void
    {
        $store = new DoctrineAdvertisingStore($this->connection());
        $placement = new Placement(Placement::VIDEO_PREROLL_ID, 'Pre-roll', [AdFormat::Video]);
        $now = new \DateTimeImmutable('2026-10-27T12:00:00+00:00');
        $first = new Advertisement(
            str_repeat('e', 32), 'First', $placement->id, AdFormat::Video,
            ['provider' => 'local', 'video_path' => '/media/first.mp4', 'poster_path' => '/media/poster.webp', 'caption' => 'First'],
            'https://example.invalid/first', AdStatus::Active, $now, $now,
        );
        $second = new Advertisement(
            str_repeat('f', 32), 'Second', $placement->id, AdFormat::Video,
            ['provider' => 'local', 'video_path' => '/media/second.mp4', 'poster_path' => '/media/poster.webp', 'caption' => 'Second'],
            'https://example.invalid/second', AdStatus::Active, $now, $now,
        );
        $store->savePlacement($placement);
        $store->saveAdvertisement($first);

        try {
            $store->saveAdvertisement($second);
            self::fail('The database must reject a second active pre-roll creative.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame([$first->id], array_map(static fn (Advertisement $ad): string => $ad->id, $store->listAdvertisementsByStatus($placement->id, AdStatus::Active)));
        }

        $connection = $this->connection();
        try {
            $connection->executeStatement("UPDATE cms_advertising_placement SET formats = '[\"html_banner\"]'::jsonb WHERE id = 'video-player-preroll'");
            self::fail('The database must reject a non-video pre-roll placement.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('23514', $exception->getMessage());
            self::assertSame([AdFormat::Video], $store->findPlacement($placement->id)?->formats);
        }

        $invalidFormat = new Advertisement(
            str_repeat('1', 32), 'Invalid format', $placement->id, AdFormat::HtmlBanner,
            ['html' => '<strong>Not a video</strong>'], 'https://example.invalid/invalid', AdStatus::Active, $now, $now,
        );
        try {
            $store->saveAdvertisement($invalidFormat);
            self::fail('The database must reject a non-video creative in the pre-roll placement.');
        } catch (DriverException $exception) {
            self::assertStringContainsString('23514', $exception->getMessage());
            self::assertNull($store->findAdvertisement($invalidFormat->id));
        }
    }

    public function testStatisticsAreAggregatedAndClickDigestsAreDeduplicated(): void
    {
        $statistics = new DoctrineStatisticsStore($this->connection());
        $at = new \DateTimeImmutable('2026-10-22T12:00:00+00:00');
        $adId = str_repeat('b', 32);

        $statistics->recordImpression($adId, 'header', $at);
        self::assertTrue($statistics->recordClick($adId, 'header', str_repeat('c', 64), $at));
        self::assertFalse($statistics->recordClick($adId, 'header', str_repeat('c', 64), $at));

        $rows = $statistics->aggregate($at, $at);

        self::assertCount(1, $rows);
        $row = $rows[0] ?? null;
        self::assertNotNull($row);
        self::assertSame(1, $row->impressions);
        self::assertSame(1, $row->clicks);
        self::assertSame($adId, $row->advertisementId);
    }

    public function testClicksAreRateLimitedByAdvertisementWithoutVisitorIdentifiers(): void
    {
        $statistics = new DoctrineStatisticsStore($this->connection());
        $at = new \DateTimeImmutable('2026-10-22T12:01:00+00:00');
        $adId = str_repeat('d', 32);
        $accepted = 0;
        for ($index = 0; $index < 601; ++$index) {
            $digest = hash('sha256', 'one-use-event-'.$index);
            if ($statistics->recordClick($adId, 'header', $digest, $at)) {
                ++$accepted;
            }
        }

        self::assertSame(600, $accepted);
        $rows = $statistics->aggregate($at, $at);
        self::assertCount(1, $rows);
        self::assertSame(600, $rows[0]->clicks);
    }

    private function connection(): Connection
    {
        return $this->connection ?? throw new \LogicException('The database connection is not available.');
    }

    private function createTemporarySchema(Connection $connection): void
    {
        $connection->executeStatement("CREATE TEMPORARY TABLE IF NOT EXISTS cms_advertising_placement (id varchar(64) PRIMARY KEY, label varchar(120) NOT NULL, formats jsonb NOT NULL, archived boolean NOT NULL DEFAULT false, paused boolean NOT NULL DEFAULT false, CHECK (id <> 'video-player-preroll' OR formats = '[\"video\"]'::jsonb))");
        $connection->executeStatement("CREATE TEMPORARY TABLE IF NOT EXISTS cms_advertising_ad (id char(32) PRIMARY KEY, label varchar(120) NOT NULL, placement_id varchar(64) NOT NULL, format varchar(32) NOT NULL, creative jsonb NOT NULL, destination_url text NOT NULL, status varchar(16) NOT NULL, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL, CHECK (placement_id <> 'video-player-preroll' OR format = 'video'))");
        $connection->executeStatement("CREATE UNIQUE INDEX IF NOT EXISTS uniq_advertising_active_video_preroll ON cms_advertising_ad (placement_id) WHERE placement_id = 'video-player-preroll' AND status = 'active'");
        $connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS cms_advertising_daily_stats (day date NOT NULL, advertisement_id char(32) NOT NULL, placement_id varchar(64) NOT NULL, impressions bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0, PRIMARY KEY (day, advertisement_id))');
        $connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS cms_advertising_click_dedupe (event_digest char(64) PRIMARY KEY, expires_at timestamptz NOT NULL)');
        $connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS cms_advertising_click_window (advertisement_id char(32) NOT NULL, minute_at timestamptz NOT NULL, click_count integer NOT NULL, PRIMARY KEY (advertisement_id, minute_at))');
        $connection->executeStatement('TRUNCATE cms_advertising_click_window, cms_advertising_click_dedupe, cms_advertising_daily_stats, cms_advertising_ad, cms_advertising_placement');
    }
}
