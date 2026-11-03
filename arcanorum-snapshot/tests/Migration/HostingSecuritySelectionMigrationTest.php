<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261103130000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HostingSecuritySelectionMigrationTest extends KernelTestCase
{
    public function testMigrationSeedsTheEmptySingletonAndRejectsUnknownFeatures(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        self::assertSame('[]', $connection->fetchOne(
            'SELECT selected_feature_ids::text FROM hosting_security_selection WHERE id = 1',
        ));

        $connection->beginTransaction();
        try {
            try {
                $connection->executeStatement(
                    "UPDATE hosting_security_selection SET selected_feature_ids = '[\"csrf\"]'::json WHERE id = 1",
                );
                self::fail('The database must reject baseline and unknown selection ids.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardSavedSelections(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                "UPDATE hosting_security_selection SET selected_feature_ids = '[\"private_storage\"]'::json WHERE id = 1",
            );

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Ausgewählte Host-Sicherheitsfunktionen müssen vor dem Migrationsrückbau erhalten oder ausdrücklich entfernt werden.');

            new Version20261103130000($connection, new NullLogger())->down(new Schema());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeFailsClosedWhenSelectionDataCannotBeRead(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with('LOCK TABLE hosting_security_selection IN SHARE ROW EXCLUSIVE MODE')
            ->willReturn(1);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with("SELECT COUNT(*) FROM hosting_security_selection WHERE selected_feature_ids::jsonb <> '[]'::jsonb")
            ->willReturn('unknown');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Die Host-Sicherheitsauswahl konnte vor dem Migrationsrückbau nicht zuverlässig geprüft werden.');

        new Version20261103130000($connection, new NullLogger())->down(new Schema());
    }
}
