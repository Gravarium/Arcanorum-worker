<?php

declare(strict_types=1);

namespace App\Tests\Module\Settings\EventSubscriber;

use App\Module\Settings\EventSubscriber\SiteSettingsRequestSubscriber;
use App\Service\SiteSettingsProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Twig\Environment;

final class SiteSettingsRequestSubscriberTest extends TestCase
{
    public function testDatabaseInstallerRouteSkipsDatabaseBackedSiteSettings(): void
    {
        $settings = new \ReflectionClass(SiteSettingsProvider::class)->newInstanceWithoutConstructor();
        $twig = new \ReflectionClass(Environment::class)->newInstanceWithoutConstructor();
        self::assertInstanceOf(SiteSettingsProvider::class, $settings);
        self::assertInstanceOf(Environment::class, $twig);

        $subscriber = new SiteSettingsRequestSubscriber($settings, $twig);
        $request = Request::create('/install/database');
        $request->attributes->set('_route', 'installer_database_index');
        $event = new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );

        $subscriber->applySiteDefaults($event);

        self::assertFalse($event->hasResponse());
        self::assertFalse($request->attributes->has('_arcanorum_site_settings'));
    }
}
