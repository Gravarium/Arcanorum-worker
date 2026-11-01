<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ContentCategoryType;
use App\Service\ContentCategoryIdentityLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContentCategoryIdentityLockTest extends KernelTestCase
{
    public function testLocksNormalizedNameAndSlugWithinTheirContentTypeUntilCommit(): void
    {
        self::bootKernel();
        $first = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $first);
        $second = DriverManager::getConnection($first->getParams());
        $lock = self::getContainer()->get(ContentCategoryIdentityLock::class);
        self::assertInstanceOf(ContentCategoryIdentityLock::class, $lock);

        $suffix = bin2hex(random_bytes(8));
        $name = 'Category Lock '.$suffix;
        $slug = 'category-lock-'.$suffix;
        $nameKey = 'arcanorum:content-category:name:video:category lock '.$suffix;
        $slugKey = 'arcanorum:content-category:slug:video:'.$slug;
        $otherTypeNameKey = 'arcanorum:content-category:name:news:category lock '.$suffix;

        try {
            $first->beginTransaction();
            $lock->lock($first, ContentCategoryType::Video, '  '.$name.'  ', $slug);
            $second->beginTransaction();

            self::assertFalse(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:identity_key, 0))',
                ['identity_key' => $nameKey],
            )));
            self::assertFalse(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:identity_key, 0))',
                ['identity_key' => $slugKey],
            )));
            self::assertTrue(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:identity_key, 0))',
                ['identity_key' => $otherTypeNameKey],
            )));

            $first->commit();

            self::assertTrue(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:identity_key, 0))',
                ['identity_key' => $nameKey],
            )));
            self::assertTrue(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:identity_key, 0))',
                ['identity_key' => $slugKey],
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

    public function testIdentityLocksRequireAnActiveTransaction(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $lock = self::getContainer()->get(ContentCategoryIdentityLock::class);
        self::assertInstanceOf(ContentCategoryIdentityLock::class, $lock);

        $this->expectException(\LogicException::class);
        $lock->lock($connection, ContentCategoryType::News, 'News', 'news');
    }

    public function testIdentityLockRejectsInvalidNamesAndSlugs(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $lock = self::getContainer()->get(ContentCategoryIdentityLock::class);
        self::assertInstanceOf(ContentCategoryIdentityLock::class, $lock);

        $connection->beginTransaction();
        try {
            try {
                $lock->lock($connection, ContentCategoryType::News, "Invalid\nname", 'valid-slug');
                self::fail('A category name with control characters must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Der zu sperrende Kategoriename ist ungültig.', $exception->getMessage());
            }

            try {
                $lock->lock($connection, ContentCategoryType::News, 'Valid name', 'Invalid Slug');
                self::fail('A malformed category slug must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('Der zu sperrende Kategorien-Slug ist ungültig.', $exception->getMessage());
            }
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
