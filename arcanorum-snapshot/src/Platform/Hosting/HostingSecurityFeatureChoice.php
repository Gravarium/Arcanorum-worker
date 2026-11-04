<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final readonly class HostingSecurityFeatureChoice
{
    public function __construct(
        public HostingSecurityFeature $feature,
        public CapabilityStatus $availability,
        public bool $selected,
    ) {
    }

    public function isAvailable(): bool
    {
        return CapabilityStatus::Verified === $this->availability;
    }

    public function hasRuntimeIntegration(): bool
    {
        return $this->feature->hasRuntimeIntegration();
    }

    public function isEnabled(): bool
    {
        return $this->selected && $this->isAvailable() && $this->hasRuntimeIntegration();
    }

    public function hasSelectionDrift(): bool
    {
        return $this->selected && !$this->isAvailable();
    }

    public function hasIntegrationGap(): bool
    {
        return $this->selected && !$this->hasRuntimeIntegration();
    }
}
