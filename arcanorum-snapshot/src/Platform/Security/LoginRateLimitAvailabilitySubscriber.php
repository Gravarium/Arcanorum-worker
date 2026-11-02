<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class LoginRateLimitAvailabilitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private DatabaseLoginRateLimiter $limiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Fehlenden gemeinsamen DB-Zähler vor Authentifizierung und optionaler Attestierung fail-closed behandeln.
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
        if (Request::METHOD_POST !== $request->getMethod() || !in_array($path, ['/admin/login', '/konto/anmelden'], true)) {
            return;
        }

        if ($this->limiter->isStorageAvailable()) {
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
}
