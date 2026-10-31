<?php

declare(strict_types=1);

namespace App\Module\Member\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class MemberSensitiveReferrerPolicySubscriber implements EventSubscriberInterface
{
    /** @var array<string, true> */
    private const array SECURITY_ROUTES = [
        'member_email_request' => true,
        'member_email_verify' => true,
        'member_password_reset_request' => true,
        'member_password_reset' => true,
    ];

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -1]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || !isset(self::SECURITY_ROUTES[$route])) {
            return;
        }

        $event->getResponse()->headers->set('Referrer-Policy', 'no-referrer');
    }
}
