<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\VideoSourceUrlLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VideoSourceUrlLockTest extends KernelTestCase
{
    public function testCanonicalSourceLockIsHeldUntilTransactionCommits(): void
    {
        self::bootKernel();
        $first = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $first);
        $second = DriverManager::getConnection($first->getParams());
        $lock = self::getContainer()->get(VideoSourceUrlLock::class);
        self::assertInstanceOf(VideoSourceUrlLock::class, $lock);

        $videoId = strtoupper(substr(bin2hex(random_bytes(8)), 0, 11));
        $sourceUrl = 'https://www.youtube.com/watch?v='.$videoId;
        $lockKey = 'arcanorum:video-source-url:'.$sourceUrl;

        try {
            $first->beginTransaction();
            $lock->lock($first, [$sourceUrl, $sourceUrl]);
            $second->beginTransaction();

            self::assertFalse(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:source_key, 0))',
                ['source_key' => $lockKey],
            )));

            $first->commit();

            self::assertTrue(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:source_key, 0))',
                ['source_key' => $lockKey],
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

    public function testSourceLocksRequireATransactionAndRejectUnsafeUrls(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $lock = self::getContainer()->get(VideoSourceUrlLock::class);
        self::assertInstanceOf(VideoSourceUrlLock::class, $lock);

        try {
            $this->expectException(\LogicException::class);
            $lock->lock($connection, []);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testUnsafeSourceIsRejectedBeforeAdvisoryLockAcquisition(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $lock = self::getContainer()->get(VideoSourceUrlLock::class);
        self::assertInstanceOf(VideoSourceUrlLock::class, $lock);

        $connection->beginTransaction();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $lock->lock($connection, ['http://videos.example.test/unsafe']);
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
