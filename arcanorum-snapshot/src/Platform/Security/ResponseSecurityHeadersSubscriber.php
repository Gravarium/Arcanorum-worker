<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ResponseSecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        if ('no-referrer' !== $headers->get('Referrer-Policy')) {
            $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $headers->set('Permissions-Policy', 'camera=(), geolocation=(), microphone=()');

        $path = AdminAreaRequestPath::decode($event->getRequest()->getPathInfo());
        if (AdminAreaRequestPath::isAdminArea($path)) {
            $headers->removeCacheControlDirective('public');
            $headers->addCacheControlDirective('private');
            $headers->addCacheControlDirective('no-store');
        }
    }
}
