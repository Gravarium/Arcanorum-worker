<?php

declare(strict_types=1);

namespace App\Module\Member\EventSubscriber;

use App\Service\SiteSettingsProvider;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class MemberRegistrationAvailabilitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SiteSettingsProvider $settings,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Run after routing and before site defaults, CSRF, and controller input handling.
        return [KernelEvents::REQUEST => ['enforceRegistrationAvailability', 18]];
    }

    public function enforceRegistrationAvailability(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || 'member_register' !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        try {
            $enabled = $this->settings->current()->isMemberRegistrationEnabled();
        } catch (DbalException $exception) {
            $this->logger->error('Registrierungsoption konnte nicht sicher gelesen werden.', [
                'failure_type' => $exception::class,
            ]);
            $event->setResponse($this->privateResponse(Response::HTTP_SERVICE_UNAVAILABLE));

            return;
        }

        if (!$enabled) {
            $event->setResponse($this->privateResponse(Response::HTTP_NOT_FOUND));
        }
    }

    private function privateResponse(int $status): Response
    {
        $response = new Response('', $status);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
