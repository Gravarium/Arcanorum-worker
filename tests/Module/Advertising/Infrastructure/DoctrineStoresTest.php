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
        $connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS cms_advertising_placement (id varchar(64) PRIMARY KEY, label varchar(120) NOT NULL, formats jsonb NOT NULL, archived boolean NOT NULL DEFAULT false, paused boolean NOT NULL DEFAULT false)');
        $connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS cms_advertising_ad (id char(32) PRIMARY KEY, label varchar(120) NOT NULL, placement_id varchar(64) NOT NULL, format varchar(32) NOT NULL, creative jsonb NOT NULL, destination_url text NOT NULL, status varchar(16) NOT NULL, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL)');
        $connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS cms_advertising_daily_stats (day date NOT NULL, advertisement_id char(32) NOT NULL, placement_id varchar(64) NOT NULL, impressions bigint NOT NULL DEFAULT 0, clicks bigint NOT NULL DEFAULT 0, PRIMARY KEY (day, advertisement_id))');
        $connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS cms_advertising_click_dedupe (event_digest char(64) PRIMARY KEY, expires_at timestamptz NOT NULL)');
        $connection->executeStatement('CREATE TEMPORARY TABLE IF NOT EXISTS cms_advertising_click_window (advertisement_id char(32) NOT NULL, minute_at timestamptz NOT NULL, click_count integer NOT NULL, PRIMARY KEY (advertisement_id, minute_at))');
        $connection->executeStatement('TRUNCATE cms_advertising_click_window, cms_advertising_click_dedupe, cms_advertising_daily_stats, cms_advertising_ad, cms_advertising_placement');
    }
}
