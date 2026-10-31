<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030060000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommentDisplaySettingsMigrationTest extends KernelTestCase
{
    public function testMigrationPreservesExistingCommentDisplayDefaults(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();

        self::assertSame(
            "'added'::character varying",
            $connection->fetchOne(
                "SELECT column_default FROM information_schema.columns WHERE table_name = 'site_settings' AND column_name = 'comment_default_sort'",
            ),
        );
        self::assertSame(
            '0',
            $connection->fetchOne('SELECT comment_rating_hide_threshold::text FROM site_settings WHERE id = 1'),
        );
    }

    public function testDowngradeRefusesToDiscardCustomizedCommentDisplaySettings(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement("UPDATE site_settings SET comment_default_sort = 'score', comment_rating_hide_threshold = 4 WHERE id = 1");

            try {
                new Version20261030060000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve customized comment display settings.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Kommentarsortierung und Abwertungsschwelle dürfen durch den Migrationsrückbau nicht verloren gehen.',
                    $exception->getMessage(),
                );
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsUnsupportedCommentSort(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $connection->executeStatement("UPDATE site_settings SET comment_default_sort = 'unsupported' WHERE id = 1");
                self::fail('The database must reject unsupported comment order values.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsCommentMuteThresholdOutsideBounds(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $connection->executeStatement('UPDATE site_settings SET comment_rating_hide_threshold = 1001 WHERE id = 1');
                self::fail('The database must reject comment mute thresholds above 1000.');
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
