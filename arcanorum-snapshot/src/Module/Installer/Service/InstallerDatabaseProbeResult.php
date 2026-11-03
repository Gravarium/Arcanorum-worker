<?php

declare(strict_types=1);

namespace App\Module\Installer\Service;

final readonly class InstallerDatabaseProbeResult
{
    public function __construct(
        public bool $connectionAvailable,
        public bool $requiredTablesAvailable,
        public ?string $failureType = null,
    ) {
    }
}
