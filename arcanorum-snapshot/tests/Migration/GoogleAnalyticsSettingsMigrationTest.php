<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030140000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GoogleAnalyticsSettingsMigrationTest extends KernelTestCase
{
    public function testMeasurementIdIsDisabledByDefaultAndDatabaseConstraintRejectsMalformedValues(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            self::assertSame('', $connection->fetchOne('SELECT google_analytics_measurement_id FROM site_settings WHERE id = 1'));
            $connection->executeStatement("UPDATE site_settings SET google_analytics_measurement_id = 'G-ABC1234567' WHERE id = 1");
            self::assertSame('G-ABC1234567', $connection->fetchOne('SELECT google_analytics_measurement_id FROM site_settings WHERE id = 1'));

            try {
                $connection->executeStatement("UPDATE site_settings SET google_analytics_measurement_id = 'G-abc1234567' WHERE id = 1");
                self::fail('The database must reject arbitrary tracking markup as a measurement ID.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardConfiguredMeasurementId(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement("UPDATE site_settings SET google_analytics_measurement_id = 'G-ABC1234567' WHERE id = 1");

            try {
                new Version20261030140000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve a configured analytics measurement ID.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Die konfigurierte Analytics-Mess-ID muss vor dem Migrationsrückbau erhalten oder exportiert werden.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }
}
