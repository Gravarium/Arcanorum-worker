<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\ScheduleFrequency;
use App\Module\Scheduling\Domain\ScheduledTask;
use App\Module\Scheduling\Domain\TaskExecutionRecord;
use App\Module\Scheduling\Domain\TaskType;
use App\Module\Scheduling\Domain\TaskStatus;

final readonly class SchedulingManager
{
    public function __construct(
        private SchedulingStore $store,
        private SchedulingAdminGuard $guard,
        private TaskHandlerRegistry $handlers,
    ) {
    }

    public function createTask(string $label, string $type, int $intervalSeconds, string $timezone, string $csrfToken): ScheduledTask
    {
        $this->authorizeMutation($csrfToken);
        $taskType = TaskType::tryFrom($type);
        if (null === $taskType) {
            throw new \InvalidArgumentException('Task type is not allowlisted.');
        }
        if (!$this->handlers->has($taskType)) {
            throw new \InvalidArgumentException('Task type has no registered safe handler.');
        }

        $now = new \DateTimeImmutable();
        $task = ScheduledTask::create(
            bin2hex(random_bytes(16)),
            $label,
            $taskType,
            ScheduleFrequency::fromInput($intervalSeconds, $timezone),
            $now,
        );
        $this->store->insertTask($task);

        return $task;
    }

    public function updateTask(string $id, string $label, string $type, int $intervalSeconds, string $timezone, string $csrfToken): ScheduledTask
    {
        $this->authorizeMutation($csrfToken);
        $task = $this->requiredTask($id);
        if (TaskStatus::Active === $task->status) {
            throw new \DomainException('Pause or disable a task before editing it.');
        }
        if ($this->store->hasUnexpiredExecution($id, new \DateTimeImmutable())) {
            throw new \DomainException('A task with a running execution cannot be edited.');
        }

        $taskType = TaskType::tryFrom($type);
        if (null === $taskType || !$this->handlers->has($taskType)) {
            throw new \InvalidArgumentException('Task type must be allowlisted and have a registered safe handler.');
        }

        $now = new \DateTimeImmutable();
        $updated = $task->revise($label, $taskType, ScheduleFrequency::fromInput($intervalSeconds, $timezone), $now);
        $this->store->updateTask($updated, $task->updatedAt, $now);

        return $updated;
    }

    public function activateTask(string $id, string $csrfToken): ScheduledTask
    {
        $this->authorizeMutation($csrfToken);
        $task = $this->requiredTask($id);
        if (!$this->handlers->has($task->type)) {
            throw new \DomainException('Task type has no registered safe handler.');
        }
        if ($this->store->hasUnexpiredExecution($id, new \DateTimeImmutable())) {
            throw new \DomainException('A task with a running execution cannot be activated again.');
        }

        $now = new \DateTimeImmutable();
        $updated = $task->activate($now);
        $this->store->updateTask($updated, $task->updatedAt, $now);

        return $updated;
    }

    public function pauseTask(string $id, string $csrfToken): ScheduledTask
    {
        return $this->transitionTask($id, 'pause', $csrfToken);
    }

    public function disableTask(string $id, string $csrfToken): ScheduledTask
    {
        return $this->transitionTask($id, 'disable', $csrfToken);
    }

    public function deleteTask(string $id, string $csrfToken): void
    {
        $this->authorizeMutation($csrfToken);
        $task = $this->requiredTask($id);
        if (TaskStatus::Disabled !== $task->status) {
            throw new \DomainException('Disable a task before deleting it.');
        }
        if (!$this->store->deleteDisabledTask($id, new \DateTimeImmutable())) {
            throw new \DomainException('A task with a running execution cannot be deleted.');
        }
    }

    /** @return list<ScheduledTask> */
    public function listTasks(): array
    {
        $this->guard->assertCanManageScheduling();

        return $this->store->listTasks();
    }

    /** @return list<TaskExecutionRecord> */
    public function executions(string $id, int $limit = 100): array
    {
        $this->guard->assertCanManageScheduling();
        $this->requiredTask($id);
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Execution history limit must be between 1 and 100.');
        }

        return $this->store->listExecutions($id, $limit);
    }

    private function transitionTask(string $id, string $transition, string $csrfToken): ScheduledTask
    {
        $this->authorizeMutation($csrfToken);
        $task = $this->requiredTask($id);
        $now = new \DateTimeImmutable();
        $updated = match ($transition) {
            'pause' => $task->pause($now),
            'disable' => $task->disable($now),
            default => throw new \LogicException('Unknown scheduling transition.'),
        };
        $this->store->updateTask($updated, $task->updatedAt, $now);

        return $updated;
    }

    private function requiredTask(string $id): ScheduledTask
    {
        return $this->store->findTask($id) ?? throw new SchedulingNotFound('Scheduled task was not found.');
    }

    private function authorizeMutation(string $csrfToken): void
    {
        $this->guard->assertCanManageScheduling();
        if ('' === trim($csrfToken)) {
            throw new SchedulingAccessDenied('A valid scheduling CSRF token is required.');
        }
        $this->guard->assertValidCsrfToken($csrfToken);
    }
}
