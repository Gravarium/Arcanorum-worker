<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261022220000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SiteAppearanceSettingsMigrationTest extends KernelTestCase
{
    public function testDatabaseRejectsUnsupportedAppearanceValues(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        foreach ([
            "public_theme = 'external'",
            "public_layout = 'unknown'",
            "theme_primary_color = '#GGGGGG'",
            "theme_accent_color = 'FFFFFF'",
        ] as $invalidUpdate) {
            $connection->beginTransaction();

            try {
                try {
                    $connection->executeStatement('UPDATE site_settings SET '.$invalidUpdate.' WHERE id = 1');
                    self::fail('The database must reject unsupported appearance values.');
                } catch (DriverException $exception) {
                    self::assertSame('23514', $exception->getSQLState());
                }
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
            }
        }
    }

    public function testDowngradeRefusesToDiscardCustomizedAppearance(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                "UPDATE site_settings SET public_theme = 'editorial', public_layout = 'magazine', theme_primary_color = '#17345A' WHERE id = 1",
            );

            try {
                new Version20261022220000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve customized appearance settings.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Abweichende Designoptionen dürfen durch den Migrationsrückbau nicht verloren gehen.',
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
