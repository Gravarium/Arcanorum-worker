<?php

declare(strict_types=1);

namespace App\Module\Backup\Service;

interface PostgresBackupExecutorInterface
{
    public function isAvailable(): bool;

    public function dump(string $targetPath): void;

    public function verifyArchive(string $archivePath): void;
}
