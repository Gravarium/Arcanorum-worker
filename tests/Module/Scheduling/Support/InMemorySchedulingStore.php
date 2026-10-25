<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Support;

use App\Module\Scheduling\Application\SchedulingStore;
use App\Module\Scheduling\Domain\ExecutionResultCode;
use App\Module\Scheduling\Domain\ExecutionState;
use App\Module\Scheduling\Domain\ScheduledTask;
use App\Module\Scheduling\Domain\TaskExecutionClaim;
use App\Module\Scheduling\Domain\TaskExecutionRecord;
use App\Module\Scheduling\Domain\TaskStatus;

final class InMemorySchedulingStore implements SchedulingStore
{
    /** @var array<string, ScheduledTask> */
    private array $tasks = [];

    /** @var array<string, TaskExecutionRecord> */
    private array $records = [];

    /** @var array<string, string> */
    private array $leaseTokens = [];

    public function insertTask(ScheduledTask $task): void
    {
        if (isset($this->tasks[$task->id])) {
            throw new \DomainException('Scheduled task already exists.');
        }

        $this->tasks[$task->id] = $task;
    }

    public function updateTask(ScheduledTask $task, \DateTimeImmutable $expectedUpdatedAt, \DateTimeImmutable $now): void
    {
        $current = $this->tasks[$task->id] ?? null;
        if (null === $current || self::timestamp($current->updatedAt) !== self::timestamp($expectedUpdatedAt)) {
            throw new \DomainException('Scheduled task changed concurrently or is no longer available.');
        }

        $this->tasks[$task->id] = $task;
    }

    public function findTask(string $id): ?ScheduledTask
    {
        return $this->tasks[$id] ?? null;
    }

    public function listTasks(): array
    {
        $tasks = array_values($this->tasks);
        usort($tasks, static fn (ScheduledTask $left, ScheduledTask $right): int => $right->createdAt <=> $left->createdAt);

        return $tasks;
    }

    public function findDueTasks(\DateTimeImmutable $now, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Task batch limit must be between 1 and 100.');
        }
        $tasks = array_values(array_filter(
            $this->tasks,
            static fn (ScheduledTask $task): bool => TaskStatus::Active === $task->status && null !== $task->nextRunAt && $task->nextRunAt <= $now,
        ));
        usort($tasks, static fn (ScheduledTask $left, ScheduledTask $right): int => ($left->nextRunAt?->getTimestamp() ?? 0) <=> ($right->nextRunAt?->getTimestamp() ?? 0));

        return array_slice($tasks, 0, $limit);
    }

    public function abandonSupersededExecutions(\DateTimeImmutable $now): void
    {
        $affectedTaskIds = [];
        foreach ($this->records as $key => $record) {
            if (ExecutionState::Running !== $record->state || null === $record->leaseExpiresAt || $record->leaseExpiresAt > $now) {
                continue;
            }
            $task = $this->tasks[$record->taskId] ?? null;
            if (null !== $task && TaskStatus::Active === $task->status && null !== $task->nextRunAt
                && self::timestamp($task->nextRunAt) === self::timestamp($record->scheduledFor)
            ) {
                continue;
            }

            $this->records[$key] = new TaskExecutionRecord(
                $record->taskId,
                $record->scheduledFor,
                ExecutionState::Abandoned,
                $record->attempt,
                ExecutionResultCode::Superseded,
                $record->startedAt,
                $now,
                null,
            );
            unset($this->leaseTokens[$key]);
            $affectedTaskIds[$record->taskId] = $record->taskId;
        }

        foreach (array_values($affectedTaskIds) as $taskId) {
            $this->pruneHistory($taskId);
        }
    }

    public function deleteDisabledTask(string $id, \DateTimeImmutable $now): bool
    {
        $task = $this->tasks[$id] ?? null;
        if (null === $task || TaskStatus::Disabled !== $task->status || $this->hasUnexpiredExecution($id, $now)) {
            return false;
        }
        unset($this->tasks[$id]);
        foreach ($this->records as $key => $record) {
            if ($record->taskId === $id) {
                unset($this->records[$key], $this->leaseTokens[$key]);
            }
        }

        return true;
    }

    public function hasUnexpiredExecution(string $taskId, \DateTimeImmutable $now): bool
    {
        foreach ($this->records as $record) {
            if ($record->taskId === $taskId && ExecutionState::Running === $record->state && null !== $record->leaseExpiresAt && $record->leaseExpiresAt > $now) {
                return true;
            }
        }

        return false;
    }

    public function claimExecution(
        string $taskId,
        \DateTimeImmutable $scheduledFor,
        \DateTimeImmutable $now,
        \DateTimeImmutable $leaseExpiresAt,
        \DateTimeImmutable $nextRunAfterAttemptLimit,
        int $maximumAttempts,
    ): ?TaskExecutionClaim {
        if ($leaseExpiresAt <= $now || $maximumAttempts < 1 || $maximumAttempts > SchedulingStore::MAXIMUM_ATTEMPTS) {
            throw new \InvalidArgumentException('Task execution lease or retry limit is invalid.');
        }
        $task = $this->tasks[$taskId] ?? null;
        if (null === $task || TaskStatus::Active !== $task->status || null === $task->nextRunAt
            || self::timestamp($task->nextRunAt) !== self::timestamp($scheduledFor) || $task->nextRunAt > $now
        ) {
            return null;
        }

        $key = self::runKey($taskId, $scheduledFor);
        $existing = $this->records[$key] ?? null;
        $attempt = 1;
        if (null !== $existing) {
            if (ExecutionState::Running !== $existing->state || null === $existing->leaseExpiresAt || $existing->leaseExpiresAt > $now) {
                return null;
            }
            if ($existing->attempt >= $maximumAttempts) {
                $this->records[$key] = new TaskExecutionRecord(
                    $taskId,
                    $scheduledFor,
                    ExecutionState::Abandoned,
                    $existing->attempt,
                    ExecutionResultCode::AttemptLimit,
                    $existing->startedAt,
                    $now,
                    null,
                );
                unset($this->leaseTokens[$key]);
                $this->tasks[$taskId] = $task->advanceAfterExecution($scheduledFor, $nextRunAfterAttemptLimit, $now);

                return null;
            }
            $attempt = $existing->attempt + 1;
        }

        $token = bin2hex(random_bytes(16));
        $idempotencyKey = hash('sha256', $taskId.'|'.$scheduledFor->getTimestamp());
        $this->leaseTokens[$key] = $token;
        $this->records[$key] = new TaskExecutionRecord($taskId, $scheduledFor, ExecutionState::Running, $attempt, null, $now, null, $leaseExpiresAt);

        return new TaskExecutionClaim($taskId, $scheduledFor, $attempt, $token, $idempotencyKey);
    }

    public function finishExecution(
        TaskExecutionClaim $claim,
        ExecutionState $state,
        ExecutionResultCode $resultCode,
        \DateTimeImmutable $finishedAt,
        \DateTimeImmutable $nextRunAt,
    ): ?TaskExecutionRecord {
        $key = self::runKey($claim->taskId, $claim->scheduledFor);
        $current = $this->records[$key] ?? null;
        if (null === $current || ExecutionState::Running !== $current->state || null === $current->leaseExpiresAt || $current->leaseExpiresAt <= $finishedAt
            || !isset($this->leaseTokens[$key]) || !hash_equals($this->leaseTokens[$key], $claim->leaseToken)
        ) {
            return null;
        }
        if (!in_array($state, [ExecutionState::Succeeded, ExecutionState::Failed], true)
            || (ExecutionState::Succeeded === $state && ExecutionResultCode::Completed !== $resultCode)
            || (ExecutionState::Failed === $state && ExecutionResultCode::Completed === $resultCode)
        ) {
            throw new \InvalidArgumentException('Finished task state and result code are inconsistent.');
        }

        $record = new TaskExecutionRecord($claim->taskId, $claim->scheduledFor, $state, $claim->attempt, $resultCode, $current->startedAt, $finishedAt, null);
        $this->records[$key] = $record;
        unset($this->leaseTokens[$key]);
        $task = $this->tasks[$claim->taskId] ?? null;
        if (null !== $task) {
            $this->tasks[$claim->taskId] = $task->advanceAfterExecution($claim->scheduledFor, $nextRunAt, $finishedAt);
        }

        $this->pruneHistory($claim->taskId);

        return $record;
    }

    public function listExecutions(string $taskId, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Execution history limit is invalid.');
        }
        $records = array_values(array_filter($this->records, static fn (TaskExecutionRecord $record): bool => $record->taskId === $taskId));
        usort($records, static fn (TaskExecutionRecord $left, TaskExecutionRecord $right): int => $right->scheduledFor <=> $left->scheduledFor);

        return array_slice($records, 0, $limit);
    }

    private function pruneHistory(string $taskId): void
    {
        $records = array_values(array_filter($this->records, static fn (TaskExecutionRecord $record): bool => $record->taskId === $taskId));
        usort($records, static fn (TaskExecutionRecord $left, TaskExecutionRecord $right): int => $right->scheduledFor <=> $left->scheduledFor);
        foreach (array_slice($records, 100) as $record) {
            $key = self::runKey($record->taskId, $record->scheduledFor);
            unset($this->records[$key], $this->leaseTokens[$key]);
        }
    }

    private static function runKey(string $taskId, \DateTimeImmutable $scheduledFor): string
    {
        return $taskId.'|'.$scheduledFor->getTimestamp();
    }

    private static function timestamp(\DateTimeImmutable $date): string
    {
        return $date->format('U.u');
    }
}
