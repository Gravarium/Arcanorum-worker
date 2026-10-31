<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PrivateObjectDeletionJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivateObjectDeletionJob>
 */
final class PrivateObjectDeletionJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivateObjectDeletionJob::class);
    }

    public function findDueForUpdate(\DateTimeImmutable $now): ?PrivateObjectDeletionJob
    {
        $job = $this->createQueryBuilder('job')
            ->andWhere('job.availableAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('job.availableAt', 'ASC')
            ->addOrderBy('job.createdAt', 'ASC')
            ->addOrderBy('job.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $job instanceof PrivateObjectDeletionJob ? $job : null;
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param list<string> $objectIds */
    public function countPendingForObjects(array $objectIds): int
    {
        if ([] === $objectIds) {
            return 0;
        }

        return (int) $this->createQueryBuilder('job')
            ->select('COUNT(job.id)')
            ->andWhere('job.objectId IN (:objectIds)')
            ->setParameter('objectIds', $objectIds)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function hasObject(string $objectId): bool
    {
        return null !== $this->findOneBy(['objectId' => $objectId]);
    }
}
