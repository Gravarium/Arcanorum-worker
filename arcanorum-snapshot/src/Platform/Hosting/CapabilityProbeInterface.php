<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

interface CapabilityProbeInterface
{
    /**
     * @return iterable<CapabilityEvidence>
     */
    public function probe(): iterable;
}
