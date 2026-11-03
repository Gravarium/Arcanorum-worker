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

    public function isEnabled(): bool
    {
        return $this->selected && $this->isAvailable();
    }

    public function hasSelectionDrift(): bool
    {
        return $this->selected && !$this->isAvailable();
    }
}
