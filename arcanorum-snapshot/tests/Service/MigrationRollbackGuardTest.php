<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationRollbackGuardTest extends TestCase
{
    #[DataProvider('unreadableCounts')]
    public function testCountRowsRejectsUnverifiableDatabaseValues(mixed $value): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT COUNT(*) FROM videos', [])
            ->willReturn($value);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A data-loss check for the migration rollback could not be evaluated safely.');

        new MigrationRollbackGuard($connection)->countRows('SELECT COUNT(*) FROM videos');
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableCounts(): iterable
    {
        yield 'false' => [false];
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => [' 0'];
        yield 'decimal' => ['0.0'];
        yield 'scientific notation' => ['1e0'];
        yield 'negative integer' => [-1];
        yield 'negative string' => ['-1'];
        yield 'integer overflow' => ['999999999999999999999999999999999999'];
    }

    #[DataProvider('readableCounts')]
    public function testCountRowsAcceptsOnlyNonnegativeIntegerCounts(int|string $value, int $expected): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT COUNT(*) FROM videos', [])
            ->willReturn($value);

        self::assertSame($expected, new MigrationRollbackGuard($connection)->countRows('SELECT COUNT(*) FROM videos'));
    }

    /** @return iterable<string, array{int|string, int}> */
    public static function readableCounts(): iterable
    {
        yield 'integer zero' => [0, 0];
        yield 'string zero' => ['0', 0];
        yield 'positive integer' => [12, 12];
        yield 'positive string' => ['12', 12];
    }

    #[DataProvider('unreadableExistenceValues')]
    public function testHasRowsRejectsUnverifiableDatabaseValues(mixed $value): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT EXISTS (SELECT 1 FROM videos)', [])
            ->willReturn($value);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A row-existence check for the migration rollback could not be evaluated safely.');

        new MigrationRollbackGuard($connection)->hasRows('SELECT EXISTS (SELECT 1 FROM videos)');
    }

    /** @return iterable<string, array{mixed}> */
    public static function unreadableExistenceValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => [' false'];
        yield 'unknown string' => ['yes'];
        yield 'out-of-range integer' => [2];
        yield 'negative integer' => [-1];
        yield 'array' => [[]];
    }

    #[DataProvider('readableExistenceValues')]
    public function testHasRowsAcceptsOnlyKnownDatabaseBooleanRepresentations(mixed $value, bool $expected): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->with('SELECT EXISTS (SELECT 1 FROM videos)', [])
            ->willReturn($value);

        self::assertSame($expected, new MigrationRollbackGuard($connection)->hasRows('SELECT EXISTS (SELECT 1 FROM videos)'));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function readableExistenceValues(): iterable
    {
        yield 'boolean false' => [false, false];
        yield 'integer zero' => [0, false];
        yield 'string zero' => ['0', false];
        yield 'PostgreSQL false' => ['f', false];
        yield 'PostgreSQL false word' => ['false', false];
        yield 'boolean true' => [true, true];
        yield 'integer one' => [1, true];
        yield 'string one' => ['1', true];
        yield 'PostgreSQL true' => ['t', true];
        yield 'PostgreSQL true word' => ['true', true];
    }

    public function testLocksTablesInStableValidatedOrder(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with('LOCK TABLE content_comments, site_settings, videos IN SHARE ROW EXCLUSIVE MODE')
            ->willReturn(0);

        new MigrationRollbackGuard($connection)->lockTables('videos', 'site_settings', 'content_comments');
    }

    public function testRejectsInvalidTableNamesBeforeIssuingLocks(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->expectException(\InvalidArgumentException::class);

        new MigrationRollbackGuard($connection)->lockTables('videos; DROP TABLE admin_users');
    }
}
