<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Twig;

use App\Module\Advertising\Application\AdvertisingDelivery;
use App\Module\Advertising\Application\AdvertisingManager;
use App\Module\Advertising\Application\AdvertisingRenderer;
use App\Module\Advertising\Application\ClickTokenSigner;
use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Twig\AdvertisingSlotExtension;
use App\Tests\Module\Advertising\Support\InMemoryAdvertisingStore;
use App\Tests\Module\Advertising\Support\InMemoryStatisticsStore;
use App\Tests\Module\Advertising\Support\RecordingAdminGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Markup;

#[CoversClass(AdvertisingSlotExtension::class)]
final class AdvertisingSlotExtensionTest extends TestCase
{
    public function testTrustedRenderedAdIsReturnedAsMarkupAndMissingSlotIsEmpty(): void
    {
        $store = new InMemoryAdvertisingStore();
        $statistics = new InMemoryStatisticsStore();
        $manager = new AdvertisingManager($store, $statistics, new RecordingAdminGuard());
        $manager->createPlacement('home-top', 'Startseite', [AdFormat::HtmlBanner->value], 'safe-test-token');
        $ad = $manager->createAdvertisement(
            'Banner',
            'home-top',
            AdFormat::HtmlBanner->value,
            ['html' => '<strong>Willkommen</strong><script>steal()</script>'],
            'https://example.invalid/campaign',
            'safe-test-token',
        );
        $manager->activateAdvertisement($ad->id, 'safe-test-token');
        $delivery = new AdvertisingDelivery($store, $statistics, new AdvertisingRenderer(new ClickTokenSigner(str_repeat('s', 64))));
        $extension = new AdvertisingSlotExtension($delivery, new NullLogger());

        $markup = $extension->renderSlot('home-top');
        $missing = $extension->renderSlot('missing-slot');

        self::assertInstanceOf(Markup::class, $markup);
        self::assertStringContainsString('<strong>Willkommen</strong>', (string) $markup);
        self::assertStringNotContainsString('<script', strtolower((string) $markup));
        self::assertSame('', (string) $missing);
    }

    public function testInvalidPlacementIdentifierDoesNotQueryDelivery(): void
    {
        $store = new InMemoryAdvertisingStore();
        $statistics = new InMemoryStatisticsStore();
        $delivery = new AdvertisingDelivery($store, $statistics, new AdvertisingRenderer(new ClickTokenSigner(str_repeat('s', 64))));
        $extension = new AdvertisingSlotExtension($delivery, new NullLogger());

        self::assertSame('', (string) $extension->renderSlot('../home-top'));
        self::assertSame([], $statistics->aggregate(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01')));
    }
}
