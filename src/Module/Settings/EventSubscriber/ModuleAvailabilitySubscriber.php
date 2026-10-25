<?php

declare(strict_types=1);

namespace App\Module\Settings\EventSubscriber;

use App\Module\Settings\Service\ModuleAvailability;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ModuleAvailabilitySubscriber implements EventSubscriberInterface
{
    public function __construct(private ModuleAvailability $modules)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Router and firewall have already matched the route and established access control.
        return [KernelEvents::REQUEST => ['denyUnavailableModuleRoute', -6]];
    }

    public function denyUnavailableModuleRoute(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->hasResponse()) {
            return;
        }

        if ($this->modules->controllerIsEnabled($event->getRequest()->attributes->get('_controller'))) {
            return;
        }

        $response = new Response('', Response::HTTP_NOT_FOUND, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
        $event->setResponse($response);
    }
}
