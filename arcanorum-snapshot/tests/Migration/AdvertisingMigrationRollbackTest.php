<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261024100000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdvertisingMigrationRollbackTest extends KernelTestCase
{
    public function testDowngradeRefusesToDiscardStoredAdvertisingData(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                "INSERT INTO cms_advertising_placement (id, label, formats, archived, paused)
                 VALUES (:id, :label, '[\"html_banner\"]'::jsonb, FALSE, FALSE)",
                ['id' => 'rollback-'.bin2hex(random_bytes(8)), 'label' => 'Rollback test placement'],
            );

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Anzeigen- und Statistikdaten müssen vor dem Migrationsrückbau gesichert oder entfernt werden.');

            new Version20261024100000($connection, new NullLogger())->down(new Schema());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeFailsClosedWhenRowExistenceCannotBeRead(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT EXISTS (SELECT 1 FROM cms_advertising_placement LIMIT 1)')
            ->willReturn('unexpected-database-result');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A row-existence check for the migration rollback could not be evaluated safely.');

        new Version20261024100000($connection, new NullLogger())->down(new Schema());
    }
}
