<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final readonly class HostingSecurityFeatureSelectionOverview
{
    /**
     * @param list<HostingSecurityFeatureChoice> $optionalFeatures
     */
    public function __construct(
        public HostingCapabilityReport $capabilityReport,
        public array $optionalFeatures,
    ) {
    }
}
