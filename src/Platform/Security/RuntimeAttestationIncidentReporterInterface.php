<?php

declare(strict_types=1);

namespace App\Platform\Security;

interface RuntimeAttestationIncidentReporterInterface
{
    /**
     * @param array<array-key, mixed> $requiredControlIds
     */
    public function report(
        string $actionId,
        RuntimeAttestationStatus $status,
        array $requiredControlIds,
    ): void;
}
