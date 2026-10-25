<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AdminActivityEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AdminActivityEntry>
 */
final class AdminActivityEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AdminActivityEntry::class);
    }

    /**
     * @return list<AdminActivityEntry>
     */
    public function findForAdmin(string $search, int $limit = 50, int $offset = 0): array
    {
        $queryBuilder = $this->createAdminQueryBuilder($search);

        /** @var list<AdminActivityEntry> $entries */
        $entries = $queryBuilder
            ->addSelect('actor')
            ->orderBy('entry.createdAt', \SortDirection::Descending)
            ->addOrderBy('entry.id', \SortDirection::Ascending)
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(100, $limit)))
            ->getQuery()
            ->getResult();

        return $entries;
    }

    public function countForAdmin(string $search): int
    {
        return (int) $this->createAdminQueryBuilder($search)
            ->select('COUNT(entry.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteAll(): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM admin_activity_entries',
        );
    }

    private function createAdminQueryBuilder(string $search): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('entry')
            ->leftJoin('entry.actor', 'actor');
        $normalizedSearch = mb_strtolower(trim($search), 'UTF-8');

        if ('' !== $normalizedSearch) {
            $queryBuilder
                ->andWhere('(LOWER(actor.email) LIKE :search OR LOWER(entry.routeName) LIKE :search)')
                ->setParameter('search', '%'.$normalizedSearch.'%');
        }

        return $queryBuilder;
    }
}
