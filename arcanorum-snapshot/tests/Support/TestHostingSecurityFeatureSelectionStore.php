<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Platform\Hosting\HostingSecurityFeature;
use App\Platform\Hosting\HostingSecurityFeatureSelectionStoreInterface;

final readonly class TestHostingSecurityFeatureSelectionStore implements HostingSecurityFeatureSelectionStoreInterface
{
    /**
     * @return list<HostingSecurityFeature>
     */
    public function selectedFeatures(): array
    {
        return [HostingSecurityFeature::PrivateStorage];
    }
}
