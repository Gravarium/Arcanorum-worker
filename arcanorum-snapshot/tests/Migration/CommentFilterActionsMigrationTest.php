<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261026220000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CommentFilterActionsMigrationTest extends KernelTestCase
{
    public function testLegacyFilterDefaultsToReviewAndDatabaseEnforcesActionAllowlist(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $suffix = bin2hex(random_bytes(6));
        $term = 'filter-migration-'.$suffix;
        $normalized = mb_strtolower($term, 'UTF-8');

        try {
            $connection->executeStatement(
                'INSERT INTO comment_filter_terms (id, term, normalized_term, created_at) VALUES (:id, :term, :normalized, CURRENT_TIMESTAMP)',
                ['id' => bin2hex(random_bytes(16)), 'term' => $term, 'normalized' => $normalized],
            );
            self::assertSame('review', $connection->fetchOne(
                'SELECT action FROM comment_filter_terms WHERE normalized_term = :normalized',
                ['normalized' => $normalized],
            ));

            $connection->executeStatement(
                "INSERT INTO comment_filter_terms (id, term, normalized_term, action, created_at) VALUES (:id, :term, :normalized, 'censor', CURRENT_TIMESTAMP)",
                ['id' => bin2hex(random_bytes(16)), 'term' => $term, 'normalized' => $normalized],
            );

            $connection->executeStatement('SAVEPOINT comment_filter_duplicate_test');
            try {
                $connection->executeStatement(
                    "INSERT INTO comment_filter_terms (id, term, normalized_term, action, created_at) VALUES (:id, :term, :normalized, 'censor', CURRENT_TIMESTAMP)",
                    ['id' => bin2hex(random_bytes(16)), 'term' => $term, 'normalized' => $normalized],
                );
                self::fail('A normalized term can only have one rule per action.');
            } catch (DriverException $exception) {
                self::assertSame('23505', $exception->getSQLState());
            } finally {
                $connection->executeStatement('ROLLBACK TO SAVEPOINT comment_filter_duplicate_test');
            }

            $connection->executeStatement('SAVEPOINT comment_filter_action_test');
            try {
                $connection->executeStatement(
                    "INSERT INTO comment_filter_terms (id, term, normalized_term, action, created_at) VALUES (:id, :term, :normalized, 'publish', CURRENT_TIMESTAMP)",
                    ['id' => bin2hex(random_bytes(16)), 'term' => $term.'-invalid', 'normalized' => $normalized.'-invalid'],
                );
                self::fail('The database must reject actions outside the fixed allowlist.');
            } catch (DriverException $exception) {
                self::assertSame('23514', $exception->getSQLState());
            } finally {
                $connection->executeStatement('ROLLBACK TO SAVEPOINT comment_filter_action_test');
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
    }

    public function testDowngradeRefusesToDiscardConfiguredFilterActions(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $suffix = bin2hex(random_bytes(6));
        $term = 'filter-migration-down-'.$suffix;
        $normalized = mb_strtolower($term, 'UTF-8');

        try {
            $connection->executeStatement(
                "INSERT INTO comment_filter_terms (id, term, normalized_term, action, created_at) VALUES (:id, :term, :normalized, 'reject', CURRENT_TIMESTAMP)",
                ['id' => bin2hex(random_bytes(16)), 'term' => $term, 'normalized' => $normalized],
            );

            try {
                new Version20261026220000($connection, new NullLogger())->down(new Schema());
                self::fail('A downgrade must preserve configured comment filter actions.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    'Kommentarfilter-Aktionen dürfen durch den Migrationsrückbau nicht verloren gehen.',
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
