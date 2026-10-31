<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class MigrationRollbackGuardConcurrencyTest extends KernelTestCase
{
    public function testRollbackLockPreventsConcurrentWritesUntilTheDataCheckFinishes(): void
    {
        self::bootKernel();
        $rollback = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $rollback);
        $writer = DriverManager::getConnection($rollback->getParams());

        try {
            $rollback->beginTransaction();
            new MigrationRollbackGuard($rollback)->lockTables('site_settings');

            $writer->beginTransaction();
            $writer->executeStatement("SET LOCAL lock_timeout = '250ms'");

            try {
                $writer->executeStatement('UPDATE site_settings SET id = id WHERE id = :id', ['id' => 1]);
                self::fail('A concurrent write was allowed while the rollback data check held its table lock.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
                self::assertTrue($rollback->isTransactionActive());
            }

            $writer->rollBack();
            $rollback->rollBack();

            $writer->beginTransaction();
            $writer->executeStatement('UPDATE site_settings SET id = id WHERE id = :id', ['id' => 1]);
            $writer->rollBack();
        } finally {
            if ($writer->isTransactionActive()) {
                $writer->rollBack();
            }
            if ($rollback->isTransactionActive()) {
                $rollback->rollBack();
            }
            $writer->close();
        }
    }
}
