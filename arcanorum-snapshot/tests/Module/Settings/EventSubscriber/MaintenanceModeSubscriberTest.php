<?php

declare(strict_types=1);

namespace App\Tests\Module\Settings\EventSubscriber;

use App\Module\Settings\EventSubscriber\MaintenanceModeSubscriber;
use App\Service\SiteSettingsProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;

final class MaintenanceModeSubscriberTest extends TestCase
{
    public function testDatabaseInstallerRouteSkipsDatabaseBackedMaintenanceSettings(): void
    {
        $settings = new \ReflectionClass(SiteSettingsProvider::class)->newInstanceWithoutConstructor();
        $twig = new \ReflectionClass(Environment::class)->newInstanceWithoutConstructor();
        self::assertInstanceOf(SiteSettingsProvider::class, $settings);
        self::assertInstanceOf(Environment::class, $twig);

        $subscriber = new MaintenanceModeSubscriber(
            $settings,
            $this->createStub(AuthorizationCheckerInterface::class),
            $twig,
        );
        $request = Request::create('/install/database');
        $request->attributes->set('_route', 'installer_database_index');
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->enforceMaintenanceMode($event);

        self::assertFalse($event->hasResponse());
    }
}
