<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ContentTagManager;
use App\Service\ContentTagNameLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContentTagNameLockTest extends KernelTestCase
{
    public function testNormalizedNameLocksAreHeldUntilCommitAndAcquiredInStableOrder(): void
    {
        self::bootKernel();
        $first = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $first);
        $second = DriverManager::getConnection($first->getParams());
        $lock = self::getContainer()->get(ContentTagNameLock::class);
        self::assertInstanceOf(ContentTagNameLock::class, $lock);

        $suffix = bin2hex(random_bytes(8));
        $firstName = 'tag alpha '.$suffix;
        $secondName = 'tag omega '.$suffix;
        $firstKey = 'arcanorum:content-tag:name:'.$firstName;
        $secondKey = 'arcanorum:content-tag:name:'.$secondName;

        try {
            $first->beginTransaction();
            $lock->lockNames($first, ['Tag Omega '.$suffix, '  TAG Alpha '.$suffix.'  ', 'tag alpha '.$suffix]);
            $second->beginTransaction();

            self::assertFalse(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:name_key, 0))',
                ['name_key' => $firstKey],
            )));
            self::assertFalse(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:name_key, 0))',
                ['name_key' => $secondKey],
            )));

            $first->commit();

            self::assertTrue(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:name_key, 0))',
                ['name_key' => $firstKey],
            )));
            self::assertTrue(self::databaseBoolean($second->fetchOne(
                'SELECT pg_try_advisory_xact_lock(hashtextextended(:name_key, 0))',
                ['name_key' => $secondKey],
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

    public function testLockNamesRequiresATransactionAndRejectsInvalidNames(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $lock = self::getContainer()->get(ContentTagNameLock::class);
        self::assertInstanceOf(ContentTagNameLock::class, $lock);

        try {
            $lock->lockNames($connection, ['valid name']);
            self::fail('A name lock outside a transaction must be rejected.');
        } catch (\LogicException $exception) {
            self::assertSame('Schlagwortnamensperren benötigen eine aktive Datenbanktransaktion.', $exception->getMessage());
        }

        $connection->beginTransaction();
        try {
            $this->expectException(\InvalidArgumentException::class);
            $lock->lockNames($connection, ["invalid\nname"]);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testManagerDeduplicatesEquivalentPendingNamesInsideTransaction(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        self::assertInstanceOf(\Doctrine\ORM\EntityManagerInterface::class, $entityManager);
        $manager = self::getContainer()->get(ContentTagManager::class);
        self::assertInstanceOf(ContentTagManager::class, $manager);
        $suffix = bin2hex(random_bytes(8));
        $connection = $entityManager->getConnection();

        $connection->beginTransaction();
        try {
            $resolved = $manager->resolve(['Tag Manager '.$suffix, '  tag manager '.$suffix.'  ']);
            self::assertCount(2, $resolved);
            self::assertSame($resolved[0], $resolved[1]);

            $entityManager->flush();
            self::assertContains($connection->fetchOne(
                'SELECT COUNT(*) FROM content_tags WHERE normalized_name = :name',
                ['name' => 'tag manager '.$suffix],
            ), [1, '1']);
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
