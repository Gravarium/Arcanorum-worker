<?php

declare(strict_types=1);

namespace App\Module\Platform\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class PublicViewPreferenceResponseSubscriber implements EventSubscriberInterface
{
    private const string COOKIE_NAME = 'arcanorum_public_view';

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['isolateViewPreferenceResponse', -32]];
    }

    public function isolateViewPreferenceResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $mode = $event->getRequest()->cookies->get(self::COOKIE_NAME);
        if (!is_string($mode) || !in_array($mode, ['compact', 'desktop'], true)) {
            return;
        }

        $response = $event->getResponse();
        if (!str_starts_with(strtolower((string) $response->headers->get('Content-Type', '')), 'text/html')) {
            return;
        }

        $response->setPrivate();
        $response->setVary(['Cookie'], false);
    }
}
