<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Application;

use App\Module\Advertising\Application\AdvertisingAccessDenied;
use App\Module\Advertising\Application\AdvertisingManager;
use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Domain\AdStatus;
use App\Module\Advertising\Domain\Advertisement;
use App\Tests\Module\Advertising\Support\InMemoryAdvertisingStore;
use App\Tests\Module\Advertising\Support\InMemoryStatisticsStore;
use App\Tests\Module\Advertising\Support\RecordingAdminGuard;
use PHPUnit\Framework\TestCase;

final class AdvertisingManagerTest extends TestCase
{
    public function testPermissionAndCsrfAreRequiredForAdminMutations(): void
    {
        [$manager, $store, $guard] = $this->newManager();
        $guard->mayManage = false;
        try {
            $manager->createPlacement('header', 'Header', [AdFormat::HtmlBanner->value], 'safe-test-token');
            self::fail('An unauthorized administrator must be denied.');
        } catch (AdvertisingAccessDenied) {
            self::assertCount(0, $store->listPlacements());
        }

        $guard->mayManage = true;
        try {
            $manager->createPlacement('header', 'Header', [AdFormat::HtmlBanner->value], 'wrong-token');
            self::fail('A request without a valid CSRF token must be denied.');
        } catch (AdvertisingAccessDenied) {
            self::assertCount(0, $store->listPlacements());
        }

        $created = $manager->createPlacement('header', 'Header', [AdFormat::HtmlBanner->value], 'safe-test-token');
        self::assertSame('header', $created->id);
    }

    public function testAdvertisementActivationPauseDeactivationAndArchiveTransitions(): void
    {
        [$manager] = $this->newManager();
        $this->createPlacement($manager, AdFormat::HtmlBanner);
        $advertisement = $this->createHtmlAdvertisement($manager);
        self::assertSame(AdStatus::Draft, $advertisement->status);

        $active = $manager->activateAdvertisement($advertisement->id, 'safe-test-token');
        self::assertSame(AdStatus::Active, $active->status);
        self::assertSame(AdStatus::Paused, $manager->pauseAdvertisement($active->id, 'safe-test-token')->status);
        $active = $manager->activateAdvertisement($active->id, 'safe-test-token');
        self::assertSame(AdStatus::Inactive, $manager->deactivateAdvertisement($active->id, 'safe-test-token')->status);

        $paused = $manager->pauseAdvertisement($manager->activateAdvertisement($active->id, 'safe-test-token')->id, 'safe-test-token');
        self::assertSame(AdStatus::Archived, $manager->archiveAdvertisement($paused->id, 'safe-test-token')->status);

        $this->expectException(\DomainException::class);
        $manager->activateAdvertisement($paused->id, 'safe-test-token');
    }

    public function testPlacementCannotBeArchivedWhileAnAdvertisementIsActive(): void
    {
        [$manager] = $this->newManager();
        $this->createPlacement($manager, AdFormat::HtmlBanner);
        $advertisement = $this->createHtmlAdvertisement($manager);
        $manager->activateAdvertisement($advertisement->id, 'safe-test-token');

        $this->expectException(\DomainException::class);
        $manager->archivePlacement('header', 'safe-test-token');
    }

    public function testUnsafeDestinationAndUnsupportedVideoProviderAreRejected(): void
    {
        [$manager] = $this->newManager();
        $this->createPlacement($manager, AdFormat::Video);
        $creative = ['provider' => 'third_party', 'video_path' => '/media/ad.mp4', 'poster_path' => '/media/ad.jpg', 'caption' => 'Ad'];
        try {
            $manager->createAdvertisement('Video', 'header', AdFormat::Video->value, $creative, 'https://example.invalid/', 'safe-test-token');
            self::fail('Unsupported providers must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('provider', $exception->getMessage());
        }

        $creative['provider'] = 'local';
        $this->expectException(\InvalidArgumentException::class);
        $manager->createAdvertisement('Video', 'header', AdFormat::Video->value, $creative, 'javascript:alert(1)', 'safe-test-token');
    }

    /** @return array{AdvertisingManager, InMemoryAdvertisingStore, RecordingAdminGuard} */
    private function newManager(): array
    {
        $store = new InMemoryAdvertisingStore();
        $guard = new RecordingAdminGuard();

        return [new AdvertisingManager($store, new InMemoryStatisticsStore(), $guard), $store, $guard];
    }

    private function createPlacement(AdvertisingManager $manager, AdFormat $format): void
    {
        $manager->createPlacement('header', 'Header', [$format->value], 'safe-test-token');
    }

    private function createHtmlAdvertisement(AdvertisingManager $manager): Advertisement
    {
        return $manager->createAdvertisement(
            'Banner',
            'header',
            AdFormat::HtmlBanner->value,
            ['html' => '<img src="/media/ad.png" alt="Ad"><script>run()</script>'],
            'https://example.invalid/campaign',
            'safe-test-token',
        );
    }
}
