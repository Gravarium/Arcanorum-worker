<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

final readonly class MigrationRollbackGuard
{
    public function __construct(private Connection $connection)
    {
    }

    public function lockTables(string ...$tables): void
    {
        if ([] === $tables) {
            throw new \InvalidArgumentException('At least one table must be locked before checking rollback data.');
        }

        foreach ($tables as $table) {
            if (1 !== preg_match('/\A[a-z_][a-z0-9_]*\z/D', $table)) {
                throw new \InvalidArgumentException('The rollback guard received an invalid table name.');
            }
        }

        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);

        $this->connection->executeStatement(sprintf(
            'LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE',
            implode(', ', $tables),
        ));
    }

    /** @param array<string, mixed> $parameters */
    public function countRows(
        string $query,
        array $parameters = [],
        string $failureMessage = 'A data-loss check for the migration rollback could not be evaluated safely.',
    ): int {
        $count = $this->connection->fetchOne($query, $parameters);
        if (is_int($count)) {
            if ($count < 0) {
                throw new \RuntimeException($failureMessage);
            }

            return $count;
        }

        if (!is_string($count) || 1 !== preg_match('/\A[0-9]+\z/D', $count)) {
            throw new \RuntimeException($failureMessage);
        }

        $validatedCount = filter_var($count, FILTER_VALIDATE_INT);
        if (!is_int($validatedCount) || $validatedCount < 0) {
            throw new \RuntimeException($failureMessage);
        }

        return $validatedCount;
    }

    /** @param array<string, mixed> $parameters */
    public function hasRows(
        string $query,
        array $parameters = [],
        string $failureMessage = 'A row-existence check for the migration rollback could not be evaluated safely.',
    ): bool {
        $exists = $this->connection->fetchOne($query, $parameters);

        if (is_bool($exists)) {
            return $exists;
        }

        if (is_int($exists) && (0 === $exists || 1 === $exists)) {
            return 1 === $exists;
        }

        if (!is_string($exists)) {
            throw new \RuntimeException($failureMessage);
        }

        return match ($exists) {
            '1', 't', 'true' => true,
            '0', 'f', 'false' => false,
            default => throw new \RuntimeException($failureMessage),
        };
    }
}
