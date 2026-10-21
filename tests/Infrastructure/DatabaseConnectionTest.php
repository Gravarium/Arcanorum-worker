<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DatabaseConnectionTest extends KernelTestCase
{
    public function testRealPostgreSql18Connection(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        $version = $connection->fetchOne('SHOW server_version');
        self::assertIsString($version);
        self::assertStringStartsWith('18', $version);
        self::assertSame(1, $connection->fetchOne('SELECT 1'));
    }

    public function testParameterizedWriteReadAndRollbackLeaveNoTable(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        self::assertFalse($connection->isTransactionActive());

        $value = "synthetic 'quoted' value; DROP TABLE example; --";
        $connection->beginTransaction();
        try {
            $connection->executeStatement('CREATE TEMP TABLE walker_foundation_probe (value text NOT NULL) ON COMMIT DROP');
            self::assertSame(1, $connection->executeStatement('INSERT INTO walker_foundation_probe (value) VALUES (?)', [$value]));
            self::assertSame($value, $connection->fetchOne('SELECT value FROM walker_foundation_probe'));
        } finally {
            $connection->rollBack();
        }

        self::assertFalse($connection->isTransactionActive());
        self::assertNull($connection->fetchOne("SELECT to_regclass('pg_temp.walker_foundation_probe')"));
    }
}
