<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final readonly class HostingCapabilityGuard
{
    public function requireVerified(HostingCapabilityReport $report, HostingCapability $capability): void
    {
        $this->requireVerifiedEvidence($report->evidenceFor($capability), $capability);
    }

    public function requireVerifiedEvidence(CapabilityEvidence $evidence, HostingCapability $capability): void
    {
        if ($evidence->capability !== $capability) {
            throw new HostingCapabilityUnavailable($capability, CapabilityStatus::Unknown);
        }

        $status = $evidence->effectiveStatus();
        if (CapabilityStatus::Verified === $status) {
            return;
        }

        throw new HostingCapabilityUnavailable($capability, $status);
    }
}
