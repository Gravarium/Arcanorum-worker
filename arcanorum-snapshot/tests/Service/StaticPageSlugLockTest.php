<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\StaticPageSlugLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StaticPageSlugLockTest extends KernelTestCase
{
    public function testStaticPageSlugLockIsHeldUntilTransactionCommits(): void
    {
        self::bootKernel();
        $first = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $first);
        $second = DriverManager::getConnection($first->getParams());
        $lock = self::getContainer()->get(StaticPageSlugLock::class);
        self::assertInstanceOf(StaticPageSlugLock::class, $lock);

        $slug = 'static-page-lock-'.bin2hex(random_bytes(8));
        $lockKey = 'arcanorum:static-page-slug:'.$slug;

        try {
            $first->beginTransaction();
            $lock->lock($first, $slug);
            $second->beginTransaction();

            self::assertFalse(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:slug_key, 0))',
                ['slug_key' => $lockKey],
            )));

            $first->commit();

            self::assertTrue(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:slug_key, 0))',
                ['slug_key' => $lockKey],
            )));
            $second->commit();
        } finally {
            if ($first->isTransactionActive()) {
                $first->rollBack();
            }
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            $second->close();
        }
    }

    public function testStaticPageSlugLockRequiresTransaction(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $lock = self::getContainer()->get(StaticPageSlugLock::class);
        self::assertInstanceOf(StaticPageSlugLock::class, $lock);

        try {
            $this->expectException(\LogicException::class);
            $lock->lock($connection, 'valid-static-page-slug');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testMalformedStaticPageSlugIsRejectedBeforeLockAcquisition(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $lock = self::getContainer()->get(StaticPageSlugLock::class);
        self::assertInstanceOf(StaticPageSlugLock::class, $lock);

        $connection->beginTransaction();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $lock->lock($connection, 'Invalid Slug');
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    private static function databaseBoolean(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't', 'true'], true);
    }
}
