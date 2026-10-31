<?php

declare(strict_types=1);

namespace App\Platform\Security;

final readonly class UnconfiguredRuntimeAttestationReportProvider implements RuntimeAttestationReportProviderInterface
{
    public function verifyCurrent(): RuntimeAttestationReport
    {
        return new RuntimeAttestationReport(
            RuntimeAttestationStatus::Unknown,
            [],
            'Unabhängige Laufzeitattestierung ist nicht angebunden.',
        );
    }
}
