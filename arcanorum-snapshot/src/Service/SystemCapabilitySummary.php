<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\CapabilityStatus;
use App\Platform\Hosting\HostingProfileDetector;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SystemCapabilitySummary
{
    public function __construct(
        private HostingProfileDetector $detector,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{
     *     checks: list<array{label: string, status: string, status_class: string, details: string}>,
     *     notices: list<string>,
     *     events: list<AdminSystemEvent>
     * }
     */
    public function current(): array
    {
        $checks = [];
        $notices = [];
        $events = [];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($this->detector->detect()->all() as $evidence) {
            $status = $evidence->effectiveStatusAt($now);
            $sourceLabel = $evidence->capability->label();
            $sourceStatus = $status->label();
            $label = $this->translator->trans($sourceLabel);
            $statusLabel = $this->translator->trans($sourceStatus);
            $checks[] = [
                'label' => $label,
                'status' => $statusLabel,
                'status_class' => $status->cssClass(),
                'details' => $this->translator->trans($evidence->details),
            ];
            if (CapabilityStatus::Verified !== $status) {
                $message = $this->translator->trans('%capability%: %status%.', [
                    '%capability%' => $label,
                    '%status%' => $statusLabel,
                ]);
                $notices[] = $message;
                $events[] = new AdminSystemEvent(
                    CapabilityStatus::Failed === $status
                        ? AdminSystemEventSeverity::Critical
                        : AdminSystemEventSeverity::Warning,
                    $message,
                    $evidence->checkedAt,
                );
            }
        }

        return ['checks' => $checks, 'notices' => $notices, 'events' => $events];
    }
}
