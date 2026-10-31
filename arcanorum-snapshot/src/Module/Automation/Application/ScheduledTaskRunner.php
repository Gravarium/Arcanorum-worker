<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskRun;
use App\Entity\ScheduledTaskRunStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ScheduledTaskRunner
{
    private const int GLOBAL_ADVISORY_LOCK = 73521941;
    private const int MAX_TASKS_PER_INVOCATION = 10;
    private const int MAX_HISTORY_PER_TASK = 200;
    private const int STALE_RUN_MINUTES = 15;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ScheduledTaskStore $tasks,
        private ScheduledTaskRunStore $runs,
        private ScheduledTaskHandlerRegistry $handlers,
    ) {
    }

    public function runDueTasks(string $identity, \DateTimeImmutable $now): int
    {
        if (!self::databaseBoolean($this->connection->fetchOne('SELECT pg_try_advisory_lock(:lock_key)', ['lock_key' => self::GLOBAL_ADVISORY_LOCK]))) {
            return 0;
        }

        try {
            $this->runs->markStaleRunning($now->modify(sprintf('-%d minutes', self::STALE_RUN_MINUTES)), $now);
            $this->entityManager->flush();
            $completed = 0;
            foreach ($this->tasks->findDue($now, self::MAX_TASKS_PER_INVOCATION) as $candidate) {
                $run = $this->claim($candidate->getId(), $identity, $now);
                if (null === $run) {
                    continue;
                }

                try {
                    $task = $this->getClaimedTask($run);
                    $summary = $this->handlers->get($task->getType())->execute($task, $now);
                    $run->succeed($summary, $now);
                } catch (\Throwable) {
                    $this->entityManager->clear();
                    $run = $this->runs->findRunById($run->getId());
                    if (!$run instanceof ScheduledTaskRun || ScheduledTaskRunStatus::Running !== $run->getStatus()) {
                        throw new \RuntimeException('Der Aufgabenlauf konnte nicht sicher abgeschlossen werden.');
                    }
                    $run->fail('Der Auftrag ist fehlgeschlagen. Prüfe die Server- und Anbieterkonfiguration.', $now);
                }
                $this->entityManager->flush();
                ++$completed;
            }

            $this->runs->pruneHistory(self::MAX_HISTORY_PER_TASK);
            $this->entityManager->flush();

            return $completed;
        } finally {
            $this->connection->fetchOne('SELECT pg_advisory_unlock(:lock_key)', ['lock_key' => self::GLOBAL_ADVISORY_LOCK]);
        }
    }

    private function claim(string $taskId, string $identity, \DateTimeImmutable $now): ?ScheduledTaskRun
    {
        $run = null;
        $this->connection->transactional(function () use ($taskId, $identity, $now, &$run): void {
            $task = $this->entityManager->find(ScheduledTask::class, $taskId, LockMode::PESSIMISTIC_WRITE);
            if (!$task instanceof ScheduledTask || !$task->isEnabled() || $task->getNextRunAt() > $now) {
                return;
            }

            $scheduledFor = $task->getNextRunAt();
            if (null !== $this->runs->findExistingForDueTime($task, $scheduledFor)) {
                $task->markRun($scheduledFor, $now);
                $this->entityManager->flush();

                return;
            }

            $run = new ScheduledTaskRun($task, $scheduledFor, $now, $identity);
            $task->markRun($scheduledFor, $now);
            $this->entityManager->persist($run);
            $this->entityManager->flush();
        });

        return $run;
    }

    private function getClaimedTask(ScheduledTaskRun $run): ScheduledTask
    {
        $task = $this->tasks->findByTaskKey($run->getTaskKey());
        if (!$task instanceof ScheduledTask) {
            throw new \RuntimeException('Der zugehörige Auftrag wurde entfernt.');
        }

        return $task;
    }

    private static function databaseBoolean(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 't' === $value;
    }
}
