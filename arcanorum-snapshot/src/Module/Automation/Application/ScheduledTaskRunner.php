<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskRun;
use App\Entity\ScheduledTaskRunStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class ScheduledTaskRunner
{
    private const int GLOBAL_ADVISORY_LOCK = 73521941;
    private const int MAX_TASKS_PER_INVOCATION = 10;
    private const int MAX_HISTORY_PER_TASK = 200;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ScheduledTaskStore $tasks,
        private ScheduledTaskRunStore $runs,
        private ScheduledTaskHandlerRegistry $handlers,
        private ClockInterface $clock,
    ) {
    }

    public function runDueTasks(string $identity, \DateTimeImmutable $now): int
    {
        if (!self::databaseBoolean($this->connection->fetchOne('SELECT pg_try_advisory_lock(:lock_key)', ['lock_key' => self::GLOBAL_ADVISORY_LOCK]))) {
            return 0;
        }

        try {
            $started = 0;
            foreach ($this->tasks->findDue($now, self::MAX_TASKS_PER_INVOCATION) as $candidate) {
                $run = $this->claim($candidate->getId(), $identity, $now);
                if (!$run instanceof ScheduledTaskRun) {
                    continue;
                }

                $leaseToken = $run->takeLeaseToken();
                try {
                    $task = $this->getClaimedTask($run);
                    $summary = $this->handlers->get($task->getType())->execute($task, $now);
                } catch (\Throwable) {
                    $recorded = $this->finishClaim(
                        $run->getId(),
                        $leaseToken,
                        false,
                        'Der Auftrag ist fehlgeschlagen. Prüfe die Server- und Anbieterkonfiguration.',
                    );

                    if ($recorded) {
                        ++$started;
                    }

                    continue;
                }

                $recorded = $this->finishClaim($run->getId(), $leaseToken, true, $summary);
                if ($recorded) {
                    ++$started;
                }
            }

            $this->runs->pruneHistory(self::MAX_HISTORY_PER_TASK);

            return $started;
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
            $existing = $this->runs->findExistingForDueTime($task, $scheduledFor);
            if ($existing instanceof ScheduledTaskRun) {
                if (ScheduledTaskRunStatus::Succeeded === $existing->getStatus() || $existing->isExhausted()) {
                    $task->markRun($scheduledFor, $now);
                    $this->entityManager->flush();

                    return;
                }

                if ($existing->hasExpiredLeaseAtMaxAttempts($now)) {
                    $existing->exhaustExpiredLease($now);
                    $task->markRun($scheduledFor, $now);
                    $this->entityManager->flush();

                    return;
                }

                if (!$existing->claimForRetry($identity, $now)) {
                    return;
                }

                $this->entityManager->flush();
                $run = $existing;

                return;
            }

            $run = new ScheduledTaskRun($task, $scheduledFor, $now, $identity);
            $this->entityManager->persist($run);
            $this->entityManager->flush();
        });

        return $run;
    }

    private function finishClaim(string $runId, string $leaseToken, bool $succeeded, string $summary): bool
    {
        $this->entityManager->clear();
        $finishedAt = $this->clock->now();
        $recorded = false;

        $this->connection->transactional(function () use ($runId, $leaseToken, $succeeded, $summary, $finishedAt, &$recorded): void {
            $run = $this->entityManager->find(ScheduledTaskRun::class, $runId, LockMode::PESSIMISTIC_WRITE);
            if (!$run instanceof ScheduledTaskRun || !$run->ownsUnexpiredLeaseAt($leaseToken, $finishedAt)) {
                return;
            }

            if ($succeeded) {
                try {
                    $run->succeed($leaseToken, $summary, $finishedAt);
                } catch (\InvalidArgumentException) {
                    $run->fail(
                        $leaseToken,
                        'Der Auftrag hat ein ungültiges Ergebnis geliefert und wurde sicher beendet.',
                        $finishedAt,
                    );
                    $succeeded = false;
                }
            } else {
                $run->fail($leaseToken, $summary, $finishedAt);
            }

            if ($succeeded || $run->isExhausted()) {
                $task = $this->tasks->findByTaskKey($run->getTaskKey());
                if ($task instanceof ScheduledTask) {
                    $lockedTask = $this->entityManager->find(ScheduledTask::class, $task->getId(), LockMode::PESSIMISTIC_WRITE);
                    if ($lockedTask instanceof ScheduledTask) {
                        $lockedTask->markRun($run->getScheduledFor(), $finishedAt);
                    }
                }
            }

            $this->entityManager->flush();
            $recorded = true;
        });

        return $recorded;
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
