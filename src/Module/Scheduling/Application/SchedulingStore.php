<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\ExecutionResultCode;
use App\Module\Scheduling\Domain\ExecutionState;
use App\Module\Scheduling\Domain\ScheduledTask;
use App\Module\Scheduling\Domain\TaskExecutionClaim;
use App\Module\Scheduling\Domain\TaskExecutionRecord;

interface SchedulingStore
{
    public const int MAXIMUM_ATTEMPTS = 10;

    public function insertTask(ScheduledTask $task): void;

    public function updateTask(ScheduledTask $task, \DateTimeImmutable $expectedUpdatedAt, \DateTimeImmutable $now): void;

    public function findTask(string $id): ?ScheduledTask;

    /** @return list<ScheduledTask> */
    public function listTasks(): array;

    /** @return list<ScheduledTask> */
    public function findDueTasks(\DateTimeImmutable $now, int $limit): array;

    public function abandonSupersededExecutions(\DateTimeImmutable $now): void;

    public function deleteDisabledTask(string $id, \DateTimeImmutable $now): bool;

    public function hasUnexpiredExecution(string $taskId, \DateTimeImmutable $now): bool;

    public function claimExecution(
        string $taskId,
        \DateTimeImmutable $scheduledFor,
        \DateTimeImmutable $now,
        \DateTimeImmutable $leaseExpiresAt,
        \DateTimeImmutable $nextRunAfterAttemptLimit,
        int $maximumAttempts,
    ): ?TaskExecutionClaim;

    public function finishExecution(
        TaskExecutionClaim $claim,
        ExecutionState $state,
        ExecutionResultCode $resultCode,
        \DateTimeImmutable $finishedAt,
        \DateTimeImmutable $nextRunAt,
    ): ?TaskExecutionRecord;

    /** @return list<TaskExecutionRecord> */
    public function listExecutions(string $taskId, int $limit): array;
}
