<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Hosting\ArrayCachePoolAdapter;
use App\Platform\Hosting\HostingAdapterSelector;
use App\Platform\Hosting\HostingCachePoolProvider;
use App\Platform\Hosting\HostingProfileDetector;
use App\Platform\Hosting\RedisCachePoolAdapter;
use App\Platform\Hosting\RedisCapabilityProbe;
use App\Platform\Hosting\RedisServerVersionProviderInterface;
use App\Platform\Security\LoginRateLimitAvailabilitySubscriber;
use App\Platform\Security\RuntimeAttestationActionGuard;
use App\Platform\Security\RuntimeAttestationIncidentReporterInterface;
use App\Platform\Security\RuntimeAttestationReportProviderInterface;
use App\Platform\Security\RuntimeAttestationRequestGuardSubscriber;
use App\Platform\Security\RuntimeAttestationRequestPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final class LoginRateLimitAvailabilitySubscriberTest extends TestCase
{
    public function testGetLoginPageRemainsAvailableWhenRedisIsUnverified(): void
    {
        $event = $this->requestEvent('/admin/login', Request::METHOD_GET);

        $this->subscriber()->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    public function testPostLoginFailsClosedWhenSharedRedisIsUnverified(): void
    {
        $event = $this->requestEvent('/admin/login', Request::METHOD_POST);

        $this->subscriber()->onKernelRequest($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('60', $response->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function testEncodedPostLoginFailsClosedWhenSharedRedisIsUnverified(): void
    {
        foreach (['/admin%2Flogin', '/%61dmin/login'] as $path) {
            $event = $this->requestEvent($path, Request::METHOD_POST);

            $this->subscriber()->onKernelRequest($event);

            $response = $event->getResponse();
            self::assertNotNull($response);
            self::assertSame(503, $response->getStatusCode());
        }
    }

    public function testUnverifiedSharedRedisBlocksLoginBeforeRemoteAttestation(): void
    {
        $reportProvider = $this->createMock(RuntimeAttestationReportProviderInterface::class);
        $reportProvider->expects(self::never())->method('verifyCurrent');

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->subscriber());
        $dispatcher->addSubscriber(new RuntimeAttestationRequestGuardSubscriber(
            $reportProvider,
            new RuntimeAttestationActionGuard(),
            new RuntimeAttestationRequestPolicy(),
            self::createStub(RuntimeAttestationIncidentReporterInterface::class),
        ));

        $event = $this->requestEvent('/admin/login', Request::METHOD_POST);
        $dispatcher->dispatch($event, KernelEvents::REQUEST);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(503, $response->getStatusCode());
    }

    private function subscriber(): LoginRateLimitAvailabilitySubscriber
    {
        $dsn = 'redis://127.0.0.1:6379';
        $redisProbe = new RedisCapabilityProbe($dsn, new class implements RedisServerVersionProviderInterface {
            public function serverVersion(string $dsn): ?string
            {
                return null;
            }
        });
        $fallback = new ArrayCachePoolAdapter();
        $provider = new HostingCachePoolProvider(
            'login-rate-limit-test',
            new HostingAdapterSelector([
                new RedisCachePoolAdapter($dsn),
                $fallback,
            ]),
            new HostingProfileDetector([$redisProbe]),
            $fallback,
        );
        $services = new ServiceLocator([
            HostingCachePoolProvider::class => static fn (): HostingCachePoolProvider => $provider,
            RedisCapabilityProbe::class => static fn (): RedisCapabilityProbe => $redisProbe,
        ]);

        return new LoginRateLimitAvailabilitySubscriber($services);
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
