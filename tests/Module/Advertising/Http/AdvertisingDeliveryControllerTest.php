<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Http;

use App\Module\Advertising\Application\AdvertisingClickService;
use App\Module\Advertising\Application\AdvertisingDelivery;
use App\Module\Advertising\Application\AdvertisingRenderer;
use App\Module\Advertising\Application\ClickTokenSigner;
use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Domain\AdStatus;
use App\Module\Advertising\Domain\Advertisement;
use App\Module\Advertising\Domain\Placement;
use App\Module\Advertising\Http\AdvertisingDeliveryController;
use App\Tests\Module\Advertising\Support\InMemoryAdvertisingStore;
use App\Tests\Module\Advertising\Support\InMemoryStatisticsStore;
use PHPUnit\Framework\TestCase;

final class AdvertisingDeliveryControllerTest extends TestCase
{
    public function testDeliverySanitizesStoredHtmlAndSetsSecurityHeaders(): void
    {
        $store = new InMemoryAdvertisingStore();
        $statistics = new InMemoryStatisticsStore();
        $placement = new Placement('header', 'Header', [AdFormat::HtmlBanner]);
        $store->savePlacement($placement);

        $now = new \DateTimeImmutable();
        $store->saveAdvertisement(new Advertisement(
            str_repeat('a', 32),
            'Banner',
            $placement->id,
            AdFormat::HtmlBanner,
            ['html' => '<img src="/media/banner.png" alt="Banner" onerror="run()"><script>run()</script>'],
            'https://example.invalid/campaign',
            AdStatus::Active,
            $now,
            $now,
        ));

        $tokens = new ClickTokenSigner(str_repeat('s', 32));
        $controller = new AdvertisingDeliveryController(
            new AdvertisingDelivery($store, $statistics, new AdvertisingRenderer($tokens)),
            new AdvertisingClickService($store, $statistics, $tokens),
        );

        $response = $controller->deliver($placement->id);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame(
            "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
            $response->headers->get('Content-Security-Policy'),
        );

        $body = (string) $response->getContent();
        self::assertStringContainsString('<img src="/media/banner.png" alt="Banner">', $body);
        self::assertStringNotContainsString('onerror', $body);
        self::assertStringNotContainsString('<script', strtolower($body));
    }
}
