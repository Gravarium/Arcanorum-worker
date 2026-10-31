<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskRun;
use App\Module\Automation\Application\ScheduledTaskRunStore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledTaskRun>
 */
final class ScheduledTaskRunRepository extends ServiceEntityRepository implements ScheduledTaskRunStore
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledTaskRun::class);
    }

    /** @return list<ScheduledTaskRun> */
    public function findRecent(int $limit = 100): array
    {
        /** @var list<ScheduledTaskRun> $runs */
        $runs = $this->createQueryBuilder('run')
            ->orderBy('run.startedAt', \SortDirection::Descending)
            ->addOrderBy('run.id', \SortDirection::Descending)
            ->setMaxResults(max(1, min(200, $limit)))
            ->getQuery()
            ->getResult();

        return $runs;
    }

    public function findExistingForDueTime(ScheduledTask $task, \DateTimeImmutable $scheduledFor): ?ScheduledTaskRun
    {
        $run = $this->createQueryBuilder('run')
            ->andWhere('run.task = :task')
            ->andWhere('run.scheduledFor = :scheduled_for')
            ->setParameter('task', $task)
            ->setParameter('scheduled_for', $scheduledFor)
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();

        return $run instanceof ScheduledTaskRun ? $run : null;
    }

    public function findRunById(string $id): ?ScheduledTaskRun
    {
        $run = $this->find($id);

        return $run instanceof ScheduledTaskRun ? $run : null;
    }

    public function detachTaskReferences(ScheduledTask $task): void
    {
        foreach ($this->findBy(['task' => $task]) as $run) {
            $run->detachFromTask();
        }
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $deleted = $this->createQueryBuilder('run')
            ->delete()
            ->andWhere('run.startedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
        if (!is_int($deleted)) {
            throw new \UnexpectedValueException('Doctrine hat eine ungültige Anzahl gelöschter Aufgabenläufe zurückgegeben.');
        }

        return $deleted;
    }

    public function pruneHistory(int $maximumPerTask): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM scheduled_task_runs WHERE id IN (SELECT id FROM (SELECT id, ROW_NUMBER() OVER (PARTITION BY task_key ORDER BY started_at DESC, id DESC) AS run_number FROM scheduled_task_runs) ranked WHERE run_number > :maximum_per_task)',
            ['maximum_per_task' => max(1, min(1000, $maximumPerTask))],
        );
    }
}
