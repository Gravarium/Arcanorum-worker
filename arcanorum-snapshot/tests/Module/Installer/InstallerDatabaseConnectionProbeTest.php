<?php

declare(strict_types=1);

namespace App\Tests\Module\Installer;

use App\Module\Installer\Service\InstallerDatabaseConnectionProbe;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class InstallerDatabaseConnectionProbeTest extends TestCase
{
    public function testReportsConnectedDatabaseAndRequiredApplicationTables(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects(self::once())
            ->method('tablesExist')
            ->with(['admin_users', 'doctrine_migration_versions'])
            ->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willReturn(1);
        $connection->expects(self::once())
            ->method('createSchemaManager')
            ->willReturn($schemaManager);

        $result = (new InstallerDatabaseConnectionProbe($this->managerRegistry($connection)))->check();

        self::assertTrue($result->connectionAvailable);
        self::assertTrue($result->requiredTablesAvailable);
        self::assertNull($result->failureType);
    }

    public function testReportsMissingRequiredTablesWithoutClaimingFullSchemaSynchronization(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects(self::once())
            ->method('tablesExist')
            ->with(['admin_users', 'doctrine_migration_versions'])
            ->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willReturn('1');
        $connection->expects(self::once())
            ->method('createSchemaManager')
            ->willReturn($schemaManager);

        $result = (new InstallerDatabaseConnectionProbe($this->managerRegistry($connection)))->check();

        self::assertTrue($result->connectionAvailable);
        self::assertFalse($result->requiredTablesAvailable);
        self::assertNull($result->failureType);
    }

    public function testDoesNotExposeConnectionExceptionMessages(): void
    {
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::once())
            ->method('getConnection')
            ->willThrowException(new \RuntimeException('database-password-must-not-leak'));

        $result = (new InstallerDatabaseConnectionProbe($managerRegistry))->check();

        self::assertFalse($result->connectionAvailable);
        self::assertFalse($result->requiredTablesAvailable);
        self::assertSame(\RuntimeException::class, $result->failureType);
        self::assertStringNotContainsString('database-password-must-not-leak', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testDatabaseMetadataInspectionFailureKeepsConnectionResultAndHidesErrorText(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willReturn(1);
        $connection->expects(self::once())
            ->method('createSchemaManager')
            ->willThrowException(new \RuntimeException('metadata-error-must-not-leak'));

        $result = (new InstallerDatabaseConnectionProbe($connection))->check();

        self::assertTrue($result->connectionAvailable);
        self::assertFalse($result->requiredTablesAvailable);
        self::assertSame(\RuntimeException::class, $result->failureType);
        self::assertStringNotContainsString('metadata-error-must-not-leak', json_encode($result, JSON_THROW_ON_ERROR));
    }

    private function managerRegistry(Connection $connection): ManagerRegistry
    {
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->expects(self::once())
            ->method('getConnection')
            ->willReturn($connection);

        return $managerRegistry;
    }
}
