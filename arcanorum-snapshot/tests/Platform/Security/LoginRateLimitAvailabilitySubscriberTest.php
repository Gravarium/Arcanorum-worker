<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\DatabaseLoginRateLimiter;
use App\Platform\Security\LoginRateLimitAvailabilitySubscriber;
use App\Platform\Security\RateLimitKeyFactory;
use App\Platform\Security\RuntimeAttestationActionGuard;
use App\Platform\Security\RuntimeAttestationIncidentReporterInterface;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\RuntimeAttestationRequestGuardSubscriber;
use App\Platform\Security\RuntimeAttestationRequestPolicy;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class LoginRateLimitAvailabilitySubscriberTest extends TestCase
{
    public function testAdminAndMemberLoginUseTheDatabaseStoreWithoutRequiringRedis(): void
    {
        foreach (['/admin/login', '/konto/anmelden'] as $path) {
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())
                ->method('fetchOne')
                ->with(
                    "SELECT to_regclass('authentication_login_rate_limits') IS NOT NULL "
                        ."AND has_table_privilege(current_user, 'authentication_login_rate_limits', 'SELECT') "
                        ."AND has_table_privilege(current_user, 'authentication_login_rate_limits', 'INSERT') "
                        ."AND has_table_privilege(current_user, 'authentication_login_rate_limits', 'UPDATE') "
                        ."AND has_table_privilege(current_user, 'authentication_login_rate_limits', 'DELETE')",
                )
                ->willReturn(true);

            $event = $this->requestEvent($path, Request::METHOD_POST);
            $this->subscriber($connection)->onKernelRequest($event);

            self::assertNull($event->getResponse());
        }
    }

    public function testGetLoginPageDoesNotDependOnRateLimitStorage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');

        $event = $this->requestEvent('/admin/login', Request::METHOD_GET);
        $this->subscriber($connection)->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testPostLoginFailsClosedWhenDatabaseRateLimitStorageIsUnavailable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willThrowException(new \RuntimeException('database unavailable'));

        $event = $this->requestEvent('/admin/login', Request::METHOD_POST);
        $this->subscriber($connection)->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
        self::assertSame('60', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function testPostLoginFailsClosedWhenDatabaseRoleCannotMaintainRateLimitCounters(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(false);

        $event = $this->requestEvent('/admin/login', Request::METHOD_POST);
        $this->subscriber($connection)->onKernelRequest($event);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $event->getResponse()?->getStatusCode());
    }

    public function testEncodedLoginPathsAlsoRequireDatabaseRateLimitStorage(): void
    {
        foreach (['/admin%2Flogin', '/%61dmin/login', '/konto%2Fanmelden'] as $path) {
            $connection = $this->createMock(Connection::class);
            $connection->expects(self::once())
                ->method('fetchOne')
                ->willThrowException(new \RuntimeException('database unavailable'));

            $event = $this->requestEvent($path, Request::METHOD_POST);
            $this->subscriber($connection)->onKernelRequest($event);

            self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $event->getResponse()?->getStatusCode());
        }
    }

    public function testUnavailableDatabaseBlocksLoginBeforeRuntimeAttestation(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willThrowException(new \RuntimeException('database unavailable'));
        $reportProvider = $this->createMock(RuntimeAttestationReportProviderInterface::class);
        $reportProvider->expects(self::never())->method('verifyCurrent');

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->subscriber($connection));
        $dispatcher->addSubscriber(new RuntimeAttestationRequestGuardSubscriber(
            $reportProvider,
            new RuntimeAttestationActionGuard(),
            new RuntimeAttestationRequestPolicy(),
            self::createStub(RuntimeAttestationIncidentReporterInterface::class),
        ));

        $event = $this->requestEvent('/admin/login', Request::METHOD_POST);
        $dispatcher->dispatch($event, KernelEvents::REQUEST);

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $event->getResponse()?->getStatusCode());
    }

    private function subscriber(Connection $connection): LoginRateLimitAvailabilitySubscriber
    {
        return new LoginRateLimitAvailabilitySubscriber(
            new DatabaseLoginRateLimiter($connection, new RateLimitKeyFactory('test-application-secret')),
        );
    }

    private function requestEvent(string $path, string $method): RequestEvent
    {
        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            Request::create($path, $method),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}
