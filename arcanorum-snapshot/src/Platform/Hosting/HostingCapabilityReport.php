<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final readonly class HostingCapabilityReport
{
    /** @var array<string, CapabilityEvidence> */
    private array $checks;

    /**
     * @param array<string, CapabilityEvidence> $checks
     */
    public function __construct(array $checks)
    {
        foreach (HostingCapability::cases() as $capability) {
            $check = $checks[$capability->value] ?? null;
            if (!$check instanceof CapabilityEvidence || $check->capability !== $capability) {
                throw new \InvalidArgumentException('Der Prüfnachweis muss jede Hostingfähigkeit genau einmal enthalten.');
            }
        }

        if (count($checks) !== count(HostingCapability::cases())) {
            throw new \InvalidArgumentException('Der Prüfnachweis enthält unbekannte Hostingfähigkeiten.');
        }

        $this->checks = $checks;
    }

    public function supports(HostingCapability $capability): bool
    {
        return CapabilityStatus::Verified === $this->statusFor($capability);
    }

    public function statusFor(HostingCapability $capability): CapabilityStatus
    {
        return $this->evidenceFor($capability)->effectiveStatus();
    }

    public function evidenceFor(HostingCapability $capability): CapabilityEvidence
    {
        $evidence = $this->checks[$capability->value] ?? null;
        if (!$evidence instanceof CapabilityEvidence) {
            throw new \LogicException('Der Hostingbericht enthält keinen Prüfnachweis für die angeforderte Fähigkeit.');
        }

        return $evidence;
    }

    /**
     * @return list<CapabilityEvidence>
     */
    public function all(): array
    {
        return array_map(
            static fn (CapabilityEvidence $evidence): CapabilityEvidence => new CapabilityEvidence(
                $evidence->capability,
                $evidence->effectiveStatus(),
                $evidence->source,
                $evidence->details,
                $evidence->checkedAt,
            ),
            array_values($this->checks),
        );
    }
}
