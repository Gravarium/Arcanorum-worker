<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261023100000;
use DoctrineMigrations\Version20261026130000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SiteSettingsLocaleMigrationTest extends KernelTestCase
{
    public function testDatabaseRejectsLocalesOutsideTheInstalledCatalog(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $connection->executeStatement("UPDATE site_settings SET default_locale = 'fr' WHERE id = 1");
                self::fail('The database must reject locales that are not installed.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseAcceptsTheInstalledArabicRightToLeftLocale(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement("UPDATE site_settings SET default_locale = 'ar' WHERE id = 1");
            self::assertSame('ar', $connection->fetchOne('SELECT default_locale FROM site_settings WHERE id = 1'));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardEnglishAsTheConfiguredLocale(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement("UPDATE site_settings SET default_locale = 'en' WHERE id = 1");

            try {
                new Version20261023100000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve the configured English locale.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Die englische Website-Sprache muss vor dem Migrationsrückbau geändert werden.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardArabicAsTheConfiguredLocale(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement("UPDATE site_settings SET default_locale = 'ar' WHERE id = 1");

            try {
                new Version20261026130000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve the configured Arabic locale.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Die arabische Website-Sprache muss vor dem Migrationsrückbau geändert werden.',
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
