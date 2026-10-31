<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Application;

use App\Module\Advertising\Application\AdvertisingClickService;
use App\Module\Advertising\Application\AdvertisingDelivery;
use App\Module\Advertising\Application\AdvertisingManager;
use App\Module\Advertising\Application\AdvertisingRenderer;
use App\Module\Advertising\Application\ClickTokenSigner;
use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Domain\AdStatus;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;
use App\Module\Advertising\Http\AdvertisingDeliveryController;
use App\Tests\Module\Advertising\Support\InMemoryAdvertisingStore;
use App\Tests\Module\Advertising\Support\InMemoryStatisticsStore;
use App\Tests\Module\Advertising\Support\RecordingAdminGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdvertisingDeliveryTest extends TestCase
{
    public function testSafeHtmlDeliveryCountsImpressionsWithoutExposingTrackers(): void
    {
        $store = new InMemoryAdvertisingStore();
        $statistics = new InMemoryStatisticsStore();
        $manager = new AdvertisingManager($store, $statistics, new RecordingAdminGuard());
        $manager->createPlacement('header', 'Header', [AdFormat::HtmlBanner->value], 'safe-test-token');
        $ad = $manager->createAdvertisement(
            'Banner', 'header', AdFormat::HtmlBanner->value,
            ['html' => '<img src="/media/banner.png" alt="Banner" onerror="run()"><script>run()</script>'],
            'https://example.invalid/path', 'safe-test-token',
        );
        $manager->activateAdvertisement($ad->id, 'safe-test-token');
        $now = new \DateTimeImmutable();
        $delivery = new AdvertisingDelivery($store, $statistics, new AdvertisingRenderer(new ClickTokenSigner(str_repeat('s', 64))));

        $html = $delivery->renderPlacement('header', $now);
        $rows = $statistics->aggregate($now, $now);

        self::assertStringContainsString('<img src="/media/banner.png" alt="Banner"', $html);
        self::assertStringContainsString('/advertising/click/'.$ad->id.'?token=', $html);
        self::assertStringNotContainsString('<script', strtolower($html));
        self::assertStringNotContainsString('onerror', strtolower($html));
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->impressions);
        self::assertSame(0, $rows[0]->clicks);
    }

    public function testClickUsesOnlyTheStoredSafeDestinationAndDeduplicatesTheToken(): void
    {
        $store = new InMemoryAdvertisingStore();
        $statistics = new InMemoryStatisticsStore();
        $manager = new AdvertisingManager($store, $statistics, new RecordingAdminGuard());
        $manager->createPlacement('player', 'Player', [AdFormat::StaticPlayer->value], 'safe-test-token');
        $ad = $manager->createAdvertisement(
            'Player ad', 'player', AdFormat::StaticPlayer->value,
            ['image_path' => '/media/ad.png', 'alt' => 'Ad', 'caption' => 'Open'],
            'https://example.invalid/campaign', 'safe-test-token',
        );
        $manager->activateAdvertisement($ad->id, 'safe-test-token');
        $now = new \DateTimeImmutable();
        $signer = new ClickTokenSigner(str_repeat('s', 64));
        $clicks = new AdvertisingClickService($store, $statistics, $signer);
        $token = $signer->issue($ad->id, $now);

        self::assertSame('https://example.invalid/campaign', $clicks->destinationForClick($ad->id, $token, $now));
        $clicks->destinationForClick($ad->id, $token, $now);
        self::assertNull($clicks->destinationForClick($ad->id, 'https://evil.invalid/', $now));

        $controller = new AdvertisingDeliveryController(
            new AdvertisingDelivery($store, $statistics, new AdvertisingRenderer($signer)),
            $clicks,
        );
        $request = Request::create('/advertising/click/'.$ad->id.'?token='.rawurlencode($token).'&url='.rawurlencode('https://evil.invalid/'));
        $response = $controller->click($ad->id, $request);

        $rows = $statistics->aggregate($now, $now);
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->clicks);
        self::assertSame('https://example.invalid/campaign', $response->headers->get('Location'));
        self::assertNotSame('https://evil.invalid/', $response->headers->get('Location'));
    }

    public function testVideoDeliveryUsesNativeLocalMediaWithoutScriptsOrProviders(): void
    {
        $store = new InMemoryAdvertisingStore();
        $statistics = new InMemoryStatisticsStore();
        $manager = new AdvertisingManager($store, $statistics, new RecordingAdminGuard());
        $manager->createPlacement('player', 'Player', [AdFormat::Video->value], 'safe-test-token');
        $ad = $manager->createAdvertisement(
            'Video', 'player', AdFormat::Video->value,
            ['provider' => 'local', 'video_path' => '/media/ad.mp4', 'poster_path' => '/media/ad.webp', 'caption' => '<Video>'],
            'https://example.invalid/video', 'safe-test-token',
        );
        $manager->activateAdvertisement($ad->id, 'safe-test-token');
        $now = new \DateTimeImmutable('2026-10-22T16:00:00+00:00');
        $delivery = new AdvertisingDelivery($store, $statistics, new AdvertisingRenderer(new ClickTokenSigner(str_repeat('s', 64))));

        $html = $delivery->renderPlacement('player', $now);

        self::assertStringContainsString('<video controls preload="none" playsinline', $html);
        self::assertStringContainsString('src="/media/ad.mp4" type="video/mp4"', $html);
        self::assertStringNotContainsString('<script', strtolower($html));
        self::assertStringContainsString('&lt;Video&gt;', $html);
    }

    public function testPreRollDeliveryMarksOnlyTheNativeAdVideoForTheGate(): void
    {
        $store = new InMemoryAdvertisingStore();
        $statistics = new InMemoryStatisticsStore();
        $manager = new AdvertisingManager($store, $statistics, new RecordingAdminGuard());
        $placement = $manager->createPlacement(Placement::VIDEO_PREROLL_ID, 'Pre-roll', [AdFormat::Video->value], 'safe-test-token');
        $advertisement = $manager->createAdvertisement(
            'Pre-roll ad', $placement->id, AdFormat::Video->value,
            ['provider' => 'local', 'video_path' => '/media/ad.mp4', 'poster_path' => '/media/ad.webp', 'caption' => 'Ad'],
            'https://example.invalid/campaign', 'safe-test-token',
        );
        $manager->activateAdvertisement($advertisement->id, 'safe-test-token');
        $now = new \DateTimeImmutable('2026-10-27T13:00:00+00:00');
        $store->saveAdvertisement(new Advertisement(
            str_repeat('b', 32),
            'Legacy duplicate pre-roll',
            $placement->id,
            AdFormat::Video,
            ['provider' => 'local', 'video_path' => '/media/legacy.mp4', 'poster_path' => '/media/poster.webp', 'caption' => 'Legacy'],
            'https://example.invalid/legacy',
            AdStatus::Active,
            $now,
            $now,
        ));
        $delivery = new AdvertisingDelivery($store, $statistics, new AdvertisingRenderer(new ClickTokenSigner(str_repeat('s', 64))));

        $html = $delivery->renderPlacement($placement->id, $now);

        self::assertStringContainsString('data-video-preroll', $html);
        self::assertStringContainsString('data-video-preroll-ad', $html);
        self::assertSame(1, substr_count($html, 'data-video-preroll-ad'));
        $rows = $statistics->aggregate($now, $now);
        self::assertCount(1, $rows);
        self::assertSame(1, $rows[0]->impressions);
    }

    public function testFailedCreativeDoesNotCountAnImpression(): void
    {
        $store = new InMemoryAdvertisingStore();
        $statistics = new InMemoryStatisticsStore();
        $now = new \DateTimeImmutable('2026-10-22T16:00:00+00:00');
        $placement = new Placement('player', 'Player', [AdFormat::Video]);
        $store->savePlacement($placement);
        $store->saveAdvertisement(new Advertisement(
            str_repeat('e', 32),
            'Invalid provider',
            $placement->id,
            AdFormat::Video,
            ['provider' => 'external'],
            'https://example.invalid/video',
            AdStatus::Active,
            $now,
            $now,
        ));
        $delivery = new AdvertisingDelivery($store, $statistics, new AdvertisingRenderer(new ClickTokenSigner(str_repeat('s', 64))));

        try {
            $delivery->renderPlacement('player', $now);
            self::fail('An unsupported video provider must not be delivered.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $statistics->aggregate($now, $now));
        }
    }

    public function testPausedPlacementStopsDeliveryAndClickRedirectsUntilReactivated(): void
    {
        $store = new InMemoryAdvertisingStore();
        $statistics = new InMemoryStatisticsStore();
        $manager = new AdvertisingManager($store, $statistics, new RecordingAdminGuard());
        $manager->createPlacement('header', 'Header', [AdFormat::HtmlBanner->value], 'safe-test-token');
        $ad = $manager->createAdvertisement(
            'Banner', 'header', AdFormat::HtmlBanner->value,
            ['html' => '<strong>Banner</strong>'],
            'https://example.invalid/campaign', 'safe-test-token',
        );
        $manager->activateAdvertisement($ad->id, 'safe-test-token');
        $now = new \DateTimeImmutable('2026-10-22T16:00:00+00:00');
        $signer = new ClickTokenSigner(str_repeat('s', 64));
        $delivery = new AdvertisingDelivery($store, $statistics, new AdvertisingRenderer($signer));
        $clicks = new AdvertisingClickService($store, $statistics, $signer);
        $token = $signer->issue($ad->id, $now);

        self::assertSame(1, substr_count($delivery->renderPlacement('header', $now), 'cms-ad--html'));
        $manager->pausePlacement('header', 'safe-test-token');
        self::assertSame('', $delivery->renderPlacement('header', $now));
        self::assertNull($clicks->destinationForClick($ad->id, $token, $now));

        $manager->activatePlacement('header', 'safe-test-token');
        self::assertSame(1, substr_count($delivery->renderPlacement('header', $now), 'cms-ad--html'));
        self::assertSame('https://example.invalid/campaign', $clicks->destinationForClick($ad->id, $token, $now));
    }
}
