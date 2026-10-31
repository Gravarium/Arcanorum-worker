<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261030070000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommentPerPageMigrationTest extends KernelTestCase
{
    public function testMigrationProvidesTheDefaultCommentPageSize(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        self::assertSame('10', $entityManager->getConnection()->fetchOne(
            'SELECT comments_per_page::text FROM site_settings WHERE id = 1',
        ));
    }

    public function testDatabaseRejectsCommentPageSizeOutsideBounds(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $connection->executeStatement('UPDATE site_settings SET comments_per_page = 101 WHERE id = 1');
                self::fail('The database must reject a comment page size above 100.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDatabaseRejectsZeroCommentPageSize(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            try {
                $connection->executeStatement('UPDATE site_settings SET comments_per_page = 0 WHERE id = 1');
                self::fail('The database must reject a zero comment page size.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardCustomizedCommentPageSize(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $connection->executeStatement('UPDATE site_settings SET comments_per_page = 25 WHERE id = 1');

            try {
                new Version20261030070000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve a customized comment page size.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Die konfigurierte Kommentarseitenlänge darf durch den Migrationsrückbau nicht verloren gehen.',
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
