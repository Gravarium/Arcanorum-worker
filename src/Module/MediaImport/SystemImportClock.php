<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final class SystemImportClock implements ImportClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
