<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

interface HostingSecurityFeatureSelectionStoreInterface
{
    /**
     * @return list<HostingSecurityFeature>
     */
    public function selectedFeatures(): array;
}
