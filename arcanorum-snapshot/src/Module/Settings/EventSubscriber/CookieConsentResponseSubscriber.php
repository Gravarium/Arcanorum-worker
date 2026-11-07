<?php

declare(strict_types=1);

namespace App\Module\Settings\EventSubscriber;

use App\Service\CookieConsentService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class CookieConsentResponseSubscriber implements EventSubscriberInterface
{
    public function __construct(private CookieConsentService $consent)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['secureConsentFormResponse', -20]];
    }

    public function secureConsentFormResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();
        if ($this->consent->csrfResponseNeedsPrivateCache($request)) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $csrfCookie = $this->consent->csrfCookieForResponse($request);
            if (null !== $csrfCookie) {
                $response->headers->setCookie($csrfCookie);
            }

            return;
        }

        if (null === $this->consent->currentChoice()
            || $response->isRedirect()
            || !str_starts_with((string) $response->headers->get('Content-Type', ''), 'text/html')
        ) {
            return;
        }

        $vary = $response->getVary();
        $hasCookieVary = array_any(
            $vary,
            static fn (mixed $value): bool => is_string($value) && 0 === strcasecmp($value, 'cookie'),
        );
        if (!$hasCookieVary) {
            $vary[] = 'Cookie';
        }
        $response->setVary($vary);
        $response->headers->set('Cache-Control', 'private, no-store');
    }
}
