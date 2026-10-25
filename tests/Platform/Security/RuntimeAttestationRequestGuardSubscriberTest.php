<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\RuntimeAttestationActionGuard;
use App\Platform\Security\RuntimeAttestationIncidentDeliveryUnavailable;
use App\Platform\Security\RuntimeAttestationIncidentReporterInterface;
use App\Platform\Security\RuntimeAttestationReport;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\RuntimeAttestationRequestGuardSubscriber;
use App\Platform\Security\RuntimeAttestationRequestPolicy;
use App\Platform\Security\RuntimeAttestationStatus;
use App\Platform\Security\UnconfiguredRuntimeAttestationReportProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RuntimeAttestationRequestGuardSubscriberTest extends TestCase
{
    public function testUnconfiguredAttestationBlocksAdminAccessWithoutLeakingReason(): void
    {
        $event = $this->requestEvent('/admin', Request::METHOD_GET);

        $this->subscriber(new UnconfiguredRuntimeAttestationReportProvider())->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('60', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringNotContainsString('Unabhängige Laufzeitattestierung', (string) $response->getContent());
    }

    public function testUnknownAttestationIsReportedWithoutProviderDetails(): void
    {
        $event = $this->requestEvent('/admin', Request::METHOD_GET);
        $reporter = $this->createMock(RuntimeAttestationIncidentReporterInterface::class);
        $reporter
            ->expects($this->once())
            ->method('report')
            ->with(
                'admin.request',
                RuntimeAttestationStatus::Unknown,
                self::callback(static fn (array $ids): bool => in_array('INV-AUTH-001', $ids, true)),
            )
            ->willThrowException(new RuntimeAttestationIncidentDeliveryUnavailable());

        $this->subscriber(new UnconfiguredRuntimeAttestationReportProvider(), $reporter)->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            'Die angeforderte Verwaltungsaktion ist zurzeit nicht verfügbar.',
            $response->getContent(),
        );
    }

    public function testCsrfProtectedPostLogoutIsNotBlockedWhenAttestationIsUnknown(): void
    {
        $event = $this->requestEvent('/admin/logout', Request::METHOD_POST);
        $reporter = self::createMock(RuntimeAttestationIncidentReporterInterface::class);
        $reporter->expects(self::never())->method('report');

        $this->subscriber(new UnconfiguredRuntimeAttestationReportProvider(), $reporter)->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testEncodedLogoutAliasRequiresAttestationWhenStatusIsUnknown(): void
    {
        $event = $this->requestEvent('/admin%2Flogout', Request::METHOD_POST);
        $reporter = self::createMock(RuntimeAttestationIncidentReporterInterface::class);
        $reporter
            ->expects(self::once())
            ->method('report')
            ->with(
                'admin.request',
                RuntimeAttestationStatus::Unknown,
                self::callback(static fn (array $ids): bool => in_array('INV-CSRF-001', $ids, true)),
            );

        $this->subscriber(new UnconfiguredRuntimeAttestationReportProvider(), $reporter)->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
    }

    public function testFreshVerifiedReportAllowsTheRequiredAdminAction(): void
    {
        $event = $this->requestEvent('/admin', Request::METHOD_GET);
        $provider = self::createMock(RuntimeAttestationReportProviderInterface::class);
        $provider->expects(self::once())
            ->method('verifyCurrent')
            ->willReturn($this->verifiedReport());

        $this->subscriber($provider)->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testExpiredReportIsRecheckedAtTheAdminActionBoundary(): void
    {
        $event = $this->requestEvent('/admin', Request::METHOD_GET);
        $provider = self::createStub(RuntimeAttestationReportProviderInterface::class);
        $provider->method('verifyCurrent')->willReturn($this->verifiedReport(
            new \DateTimeImmutable('-61 seconds', new \DateTimeZone('UTC')),
        ));

        $this->subscriber($provider)->onKernelRequest($event);

        self::assertSame(503, $event->getResponse()?->getStatusCode());
    }

    public function testProviderFailureFailsClosedWithoutLeakingInternalDetails(): void
    {
        $event = $this->requestEvent('/admin', Request::METHOD_GET);
        $provider = self::createStub(RuntimeAttestationReportProviderInterface::class);
        $provider->method('verifyCurrent')->willThrowException(
            new \RuntimeException('secret attestor path /srv/private'),
        );

        $this->subscriber($provider)->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('secret attestor path', (string) $response->getContent());
    }

    public function testLoginPageDoesNotCallAttestationProvider(): void
    {
        $event = $this->requestEvent('/admin/login', Request::METHOD_GET);
        $provider = self::createMock(RuntimeAttestationReportProviderInterface::class);
        $provider->expects(self::never())->method('verifyCurrent');

        $this->subscriber($provider)->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testProtectedAdminSubrequestIsAlsoBlockedWhenAttestationIsUnknown(): void
    {
        $event = $this->requestEvent('/admin', Request::METHOD_GET, HttpKernelInterface::SUB_REQUEST);

        $this->subscriber(new UnconfiguredRuntimeAttestationReportProvider())->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('60', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function subscriber(
        RuntimeAttestationReportProviderInterface $reportProvider,
        ?RuntimeAttestationIncidentReporterInterface $incidentReporter = null,
    ): RuntimeAttestationRequestGuardSubscriber {
        $incidentReporter ??= self::createMock(RuntimeAttestationIncidentReporterInterface::class);

        return new RuntimeAttestationRequestGuardSubscriber(
            $reportProvider,
            new RuntimeAttestationActionGuard(),
            new RuntimeAttestationRequestPolicy(),
            $incidentReporter,
        );
    }

    private function verifiedReport(?\DateTimeImmutable $checkedAt = null): RuntimeAttestationReport
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return new RuntimeAttestationReport(
            RuntimeAttestationStatus::Verified,
            [
                'INV-AUTH-001' => RuntimeAttestationStatus::Verified,
                'INV-SESSION-001' => RuntimeAttestationStatus::Verified,
                'INV-APP-INTEGRITY-001' => RuntimeAttestationStatus::Verified,
                'INV-WEBSERVER-CONFIG-001' => RuntimeAttestationStatus::Verified,
            ],
            'unit-test',
            $checkedAt ?? $now,
            $now->add(new \DateInterval('PT60S')),
        );
    }

    private function requestEvent(
        string $path,
        string $method,
        int $requestType = HttpKernelInterface::MAIN_REQUEST,
    ): RequestEvent {
        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create($path, $method),
            $requestType,
        );
    }
}
