<?php

declare(strict_types=1);

namespace App\Platform\Security;

use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingCachePoolProvider;
use App\Platform\Hosting\HostingCapability;
use App\Platform\Hosting\RedisCapabilityProbe;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class LoginRateLimitAvailabilitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[AutowireLocator([
            HostingCachePoolProvider::class,
            RedisCapabilityProbe::class,
        ])]
        private ContainerInterface $services,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Fehlendes Shared Redis vor der externen Attestierungsprüfung fail-closed behandeln.
            KernelEvents::REQUEST => ['onKernelRequest', 21],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = AdminAreaRequestPath::decode($request->getPathInfo());
        if (Request::METHOD_POST !== $request->getMethod() || '/admin/login' !== $path) {
            return;
        }

        if ($this->hasSharedLimiterStorage()) {
            return;
        }

        $event->setResponse(new Response(
            'Die Anmeldung ist zurzeit nicht verfügbar.',
            Response::HTTP_SERVICE_UNAVAILABLE,
            [
                'Cache-Control' => 'no-store',
                'Retry-After' => '60',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ],
        ));
    }

    private function hasSharedLimiterStorage(): bool
    {
        try {
            /** @var HostingCachePoolProvider $cachePool */
            $cachePool = $this->services->get(HostingCachePoolProvider::class);
            if (!$cachePool->usesRedisPool()) {
                return false;
            }

            /** @var RedisCapabilityProbe $redisProbe */
            $redisProbe = $this->services->get(RedisCapabilityProbe::class);
            $statuses = [];
            foreach ($redisProbe->probe() as $evidence) {
                $key = $evidence->capability->value;
                if (array_key_exists($key, $statuses)) {
                    return false;
                }

                $statuses[$key] = $evidence->effectiveStatus();
            }

            return CapabilityStatus::Verified === ($statuses[HostingCapability::RedisServer8->value] ?? null)
                && CapabilityStatus::Verified === ($statuses[HostingCapability::RedisPrivateEndpoint->value] ?? null);
        } catch (\Throwable) {
            return false;
        }
    }
}
