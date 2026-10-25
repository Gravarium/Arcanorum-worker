<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\SqlLikePattern;
use Doctrine\DBAL\Connection;

final readonly class SearchTermStatisticsRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(string $term, \DateTimeImmutable $now): void
    {
        if ('' === $term
            || mb_strlen($term, 'UTF-8') > 100
            || 1 !== preg_match('//u', $term)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $term)
        ) {
            throw new \InvalidArgumentException('Der Suchbegriff ist ungültig.');
        }

        $this->purgeExpired($now);
        $this->connection->executeStatement(
            'INSERT INTO search_term_daily (day, term, search_count, last_at) VALUES (:day, :term, 1, :last_at) '
            .'ON CONFLICT (day, term) DO UPDATE SET search_count = CASE WHEN search_term_daily.search_count < 2147483647 THEN search_term_daily.search_count + 1 ELSE search_term_daily.search_count END, last_at = EXCLUDED.last_at',
            [
                'day' => $now->format('Y-m-d'),
                'term' => $term,
                'last_at' => $now->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function purgeExpired(\DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'DELETE FROM search_term_daily WHERE day < :cutoff',
            ['cutoff' => $now->modify('-29 days')->format('Y-m-d')],
        );
    }

    /** @return list<array<string, mixed>> */
    public function findForAdmin(string $search, int $limit, int $offset): array
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('day', 'term', 'search_count', 'last_at')
            ->from('search_term_daily')
            ->orderBy('day', 'DESC')
            ->addOrderBy('search_count', 'DESC')
            ->addOrderBy('term', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)));
        if ('' !== $search) {
            $queryBuilder
                ->where('term LIKE :search')
                ->setParameter('search', '%'.SqlLikePattern::escape($search).'%');
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    public function countForAdmin(string $search): int
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('search_term_daily');
        if ('' !== $search) {
            $queryBuilder
                ->where('term LIKE :search')
                ->setParameter('search', '%'.SqlLikePattern::escape($search).'%');
        }
        $count = $queryBuilder->executeQuery()->fetchOne();

        if (is_int($count)) {
            return $count;
        }

        return is_string($count) && ctype_digit($count) ? (int) $count : 0;
    }

    public function clear(): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM search_term_daily');
    }
}
