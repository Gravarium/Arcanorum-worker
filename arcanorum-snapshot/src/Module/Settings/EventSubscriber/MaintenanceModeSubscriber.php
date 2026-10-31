<?php

declare(strict_types=1);

namespace App\Module\Settings\EventSubscriber;

use App\Service\SiteSettingsProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;

final readonly class MaintenanceModeSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SiteSettingsProvider $settings,
        private AuthorizationCheckerInterface $authorizationChecker,
        private Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['enforceMaintenanceMode', -5]];
    }

    public function enforceMaintenanceMode(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $settings = $this->settings->current();
        if (!$settings->isMaintenanceModeEnabled()
            || $this->authorizationChecker->isGranted('ROLE_ADMIN')
            || in_array($request->attributes->get('_route'), ['admin_login', 'robots_txt'], true)
        ) {
            return;
        }

        $response = new Response(
            $this->twig->render('maintenance.html.twig', [
                'site_name' => $settings->getSiteName(),
                'maintenance_message' => $settings->getMaintenanceMessage(),
            ]),
            Response::HTTP_SERVICE_UNAVAILABLE,
            [
                'Cache-Control' => 'no-store',
                'Retry-After' => '300',
                'X-Robots-Tag' => 'noindex, nofollow',
            ],
        );
        $event->setResponse($response);
    }
}
