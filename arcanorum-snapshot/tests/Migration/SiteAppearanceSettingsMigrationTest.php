<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261022220000;
use DoctrineMigrations\Version20261029220000;
use DoctrineMigrations\Version20261029230000;
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

    public function testDatabaseRejectsUnsafeExtendedThemeValues(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        foreach ([
            "theme_heading_color = 'url(!!)'",
            "theme_navigation_link_color = '#GGGGGG'",
            'theme_content_width = 1921',
        ] as $invalidUpdate) {
            $connection->beginTransaction();

            try {
                try {
                    $connection->executeStatement('UPDATE site_settings SET '.$invalidUpdate.' WHERE id = 1');
                    self::fail('The database must reject unsafe extended theme values.');
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

    public function testExtendedThemeDowngradeRefusesToDiscardCustomizedValues(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement('UPDATE site_settings SET theme_content_width = 1440 WHERE id = 1');

            try {
                new Version20261029220000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve customized public theme settings.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Abweichende öffentliche Farben oder Inhaltsbreiten dürfen durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsPartialAndUnsafeSiteLogoMetadata(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        foreach ([
            "site_logo_object_id = '".str_repeat('a', 64)."'",
            "site_logo_object_id = '".str_repeat('a', 64)."', site_logo_mime_type = 'image/svg+xml', site_logo_size = 10, site_logo_sha256 = '".str_repeat('b', 64)."'",
            "site_logo_object_id = '".str_repeat('a', 64)."', site_logo_mime_type = 'image/png', site_logo_size = 524289, site_logo_sha256 = '".str_repeat('b', 64)."'",
        ] as $invalidUpdate) {
            $connection->beginTransaction();

            try {
                try {
                    $connection->executeStatement('UPDATE site_settings SET '.$invalidUpdate.' WHERE id = 1');
                    self::fail('The database must reject incomplete or unsafe logo metadata.');
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

    public function testLogoDowngradeRefusesToDiscardConfiguredLogo(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                'UPDATE site_settings SET site_logo_object_id = :object_id, site_logo_mime_type = :mime, site_logo_size = :size, site_logo_sha256 = :sha WHERE id = 1',
                [
                    'object_id' => str_repeat('a', 64),
                    'mime' => 'image/png',
                    'size' => 512,
                    'sha' => str_repeat('b', 64),
                ],
            );

            try {
                new Version20261029230000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve a configured website logo.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Ein konfiguriertes Website-Logo darf durch den Migrationsrückbau nicht verloren gehen.',
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
