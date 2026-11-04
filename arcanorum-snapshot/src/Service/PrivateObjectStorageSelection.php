<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\HostingSecurityFeature;
use App\Platform\Hosting\HostingSecurityFeatureSelectionStoreInterface;
use Symfony\Contracts\Service\ResetInterface;

final class PrivateObjectStorageSelection implements ResetInterface
{
    private bool $enabled = false;

    public function __construct(
        private readonly HostingSecurityFeatureSelectionStoreInterface $selectionStore,
    ) {
        $this->reset();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function reset(): void
    {
        try {
            $selectedFeatures = $this->selectionStore->selectedFeatures();
        } catch (\Throwable) {
            $this->enabled = false;

            return;
        }

        $this->enabled = in_array(HostingSecurityFeature::PrivateStorage, $selectedFeatures, true);
    }
}
