<?php

declare(strict_types=1);

namespace App\Module\Installer\Service;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

final readonly class InstallerDatabaseConnectionProbe
{
    private const array REQUIRED_TABLES = [
        'admin_users',
        'doctrine_migration_versions',
    ];

    public function __construct(private ManagerRegistry $managerRegistry)
    {
    }

    public function check(): InstallerDatabaseProbeResult
    {
        try {
            $connection = $this->managerRegistry->getConnection();
            if (!$connection instanceof Connection) {
                return new InstallerDatabaseProbeResult(false, false, 'unexpected-connection-type');
            }

            $connectionResponse = $connection->fetchOne('SELECT 1');
        } catch (\Throwable $exception) {
            return new InstallerDatabaseProbeResult(false, false, $exception::class);
        }

        if (!in_array($connectionResponse, [1, '1'], true)) {
            return new InstallerDatabaseProbeResult(true, false, 'unexpected-query-result');
        }

        try {
            $requiredTablesAvailable = $connection
                ->createSchemaManager()
                ->tablesExist(self::REQUIRED_TABLES);
        } catch (\Throwable $exception) {
            return new InstallerDatabaseProbeResult(true, false, $exception::class);
        }

        return new InstallerDatabaseProbeResult(true, $requiredTablesAvailable);
    }
}
