<?php

declare(strict_types=1);

namespace App\Platform\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class RuntimeAttestationRequestGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RuntimeAttestationReportProviderInterface $reportProvider,
        private RuntimeAttestationActionGuard $actionGuard,
        private RuntimeAttestationRequestPolicy $requestPolicy,
        private RuntimeAttestationIncidentReporterInterface $incidentReporter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $requiredControlIds = $this->requestPolicy->requiredControlIdsFor($event->getRequest());
        if (null === $requiredControlIds) {
            return;
        }

        try {
            $report = $this->reportProvider->verifyCurrent();
            $this->actionGuard->requireVerified($report, ...$requiredControlIds);
        } catch (\Throwable $failure) {
            $status = $failure instanceof RuntimeAttestationUnavailable
                ? $failure->status
                : RuntimeAttestationStatus::Unknown;

            try {
                $this->incidentReporter->report('admin.request', $status, $requiredControlIds);
            } catch (\Throwable) {
                // Der fehlende Alarm ändert nichts an der fail-closed-Sperre.
            }

            $event->setResponse(new Response(
                'Die angeforderte Verwaltungsaktion ist zurzeit nicht verfügbar.',
                Response::HTTP_SERVICE_UNAVAILABLE,
                [
                    'Cache-Control' => 'no-store',
                    'Retry-After' => '60',
                    'Content-Type' => 'text/plain; charset=UTF-8',
                ],
            ));
        }
    }
}
