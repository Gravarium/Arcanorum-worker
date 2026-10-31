<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskRun;
use App\Entity\ScheduledTaskType;
use App\Module\Automation\Application\ScheduledTaskStore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledTask>
 */
final class ScheduledTaskRepository extends ServiceEntityRepository implements ScheduledTaskStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledTask::class);
    }

    /** @return list<ScheduledTask> */
    public function findForAdmin(): array
    {
        /** @var list<ScheduledTask> $tasks */
        $tasks = $this->createQueryBuilder('task')
            ->orderBy('task.systemTask', \SortDirection::Descending)
            ->addOrderBy('task.name', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $tasks;
    }

    public function findByTaskKey(string $taskKey): ?ScheduledTask
    {
        return $this->findOneBy(['taskKey' => $taskKey]);
    }

    public function countAll(): int
    {
        return $this->count([]);
    }

    /** @return list<ScheduledTask> */
    public function findDue(\DateTimeImmutable $now, int $limit = 10): array
    {
        $blockedRuns = $this->getEntityManager()->createQueryBuilder()
            ->select('blockedRun.id')
            ->from(ScheduledTaskRun::class, 'blockedRun')
            ->andWhere('blockedRun.task = task')
            ->andWhere('blockedRun.scheduledFor = task.nextRunAt')
            ->andWhere('(blockedRun.status = :running_status AND blockedRun.leaseExpiresAt > :now OR blockedRun.status = :failed_status AND blockedRun.attemptCount < :maximum_attempts AND blockedRun.retryAfter > :now)');

        /** @var list<ScheduledTask> $tasks */
        $tasks = $this->createQueryBuilder('task')
            ->andWhere('task.enabled = :enabled')
            ->andWhere('task.nextRunAt <= :now')
            ->andWhere('NOT EXISTS ('.$blockedRuns->getDQL().')')
            ->setParameter('enabled', true)
            ->setParameter('now', $now)
            ->setParameter('running_status', 'running')
            ->setParameter('failed_status', 'failed')
            ->setParameter('maximum_attempts', ScheduledTaskRun::MAX_ATTEMPTS)
            ->orderBy('task.nextRunAt', \SortDirection::Ascending)
            ->addOrderBy('task.id', \SortDirection::Ascending)
            ->setMaxResults(max(1, min(25, $limit)))
            ->getQuery()
            ->getResult();

        return $tasks;
    }

    /** @return list<ScheduledTask> */
    public function findDueByType(ScheduledTaskType $type, \DateTimeImmutable $now, int $limit): array
    {
        /** @var list<ScheduledTask> $tasks */
        $tasks = $this->createQueryBuilder('task')
            ->andWhere('task.type = :type')
            ->andWhere('task.enabled = :enabled')
            ->andWhere('task.nextRunAt <= :now')
            ->setParameter('type', $type)
            ->setParameter('enabled', true)
            ->setParameter('now', $now)
            ->orderBy('task.nextRunAt', \SortDirection::Ascending)
            ->setMaxResults(max(1, min(25, $limit)))
            ->getQuery()
            ->getResult();

        return $tasks;
    }
}
