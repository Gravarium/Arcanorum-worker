<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Http;

use App\Module\Advertising\Application\AdvertisingManager;
use App\Module\Advertising\Domain\AdFormat;
use App\Module\Advertising\Http\AdvertisingAdminController;
use App\Tests\Module\Advertising\Support\InMemoryAdvertisingStore;
use App\Tests\Module\Advertising\Support\InMemoryStatisticsStore;
use App\Tests\Module\Advertising\Support\RecordingAdminGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdvertisingAdminControllerTest extends TestCase
{
    public function testAdministrativeMutationRejectsMissingCsrfToken(): void
    {
        $guard = new RecordingAdminGuard();
        $controller = new AdvertisingAdminController(new AdvertisingManager(new InMemoryAdvertisingStore(), new InMemoryStatisticsStore(), $guard));
        $request = Request::create('/admin/advertising/placements', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"id":"header","label":"Header","formats":["html_banner"]}');

        $response = $controller->createPlacement($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('{"error":"forbidden"}', $response->getContent());
    }

    public function testAdministrativeMutationAcceptsCsrfTokenAfterPermissionCheck(): void
    {
        $guard = new RecordingAdminGuard();
        $controller = new AdvertisingAdminController(new AdvertisingManager(new InMemoryAdvertisingStore(), new InMemoryStatisticsStore(), $guard));
        $request = Request::create(
            '/admin/advertising/placements',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'safe-test-token'],
            content: '{"id":"header","label":"Header","formats":["html_banner"]}',
        );

        $response = $controller->createPlacement($request);

        self::assertSame(201, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertStringContainsString('"id":"header"', (string) $response->getContent());
    }

    public function testArchiveActionAcceptsAnEmptyRequestBody(): void
    {
        $store = new InMemoryAdvertisingStore();
        $guard = new RecordingAdminGuard();
        $manager = new AdvertisingManager($store, new InMemoryStatisticsStore(), $guard);
        $manager->createPlacement('header', 'Header', [AdFormat::HtmlBanner->value], 'safe-test-token');
        $controller = new AdvertisingAdminController($manager);
        $request = Request::create('/admin/advertising/placements/header', 'DELETE', server: ['HTTP_X_CSRF_TOKEN' => 'safe-test-token']);

        $response = $controller->archivePlacement('header', $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('"archived":true', (string) $response->getContent());
    }

    public function testPlacementCanBePausedAndActivatedThroughAdminApi(): void
    {
        $store = new InMemoryAdvertisingStore();
        $guard = new RecordingAdminGuard();
        $manager = new AdvertisingManager($store, new InMemoryStatisticsStore(), $guard);
        $manager->createPlacement('header', 'Header', [AdFormat::HtmlBanner->value], 'safe-test-token');
        $controller = new AdvertisingAdminController($manager);
        $request = Request::create('/admin/advertising/placements/header/pause', 'POST', server: ['HTTP_X_CSRF_TOKEN' => 'safe-test-token']);

        $paused = $controller->pausePlacement('header', $request);
        self::assertSame(200, $paused->getStatusCode());
        self::assertStringContainsString('"status":"paused"', (string) $paused->getContent());

        $activated = $controller->activatePlacement('header', $request);
        self::assertSame(200, $activated->getStatusCode());
        self::assertStringContainsString('"status":"active"', (string) $activated->getContent());
    }

    public function testStatisticsApiRejectsRelativeDates(): void
    {
        $guard = new RecordingAdminGuard();
        $controller = new AdvertisingAdminController(new AdvertisingManager(new InMemoryAdvertisingStore(), new InMemoryStatisticsStore(), $guard));
        $request = Request::create('/admin/advertising/statistics?from=yesterday&through=today');

        $response = $controller->statistics($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"error":"invalid_request"}', $response->getContent());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
}
