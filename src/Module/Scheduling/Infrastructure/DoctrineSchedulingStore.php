<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Infrastructure;

use App\Module\Scheduling\Application\SchedulingStore;
use App\Module\Scheduling\Domain\ExecutionResultCode;
use App\Module\Scheduling\Domain\ExecutionState;
use App\Module\Scheduling\Domain\ScheduleFrequency;
use App\Module\Scheduling\Domain\ScheduledTask;
use App\Module\Scheduling\Domain\TaskExecutionClaim;
use App\Module\Scheduling\Domain\TaskExecutionRecord;
use App\Module\Scheduling\Domain\TaskStatus;
use App\Module\Scheduling\Domain\TaskType;
use Doctrine\DBAL\Connection;

final readonly class DoctrineSchedulingStore implements SchedulingStore
{
    public const int MAXIMUM_EXECUTION_HISTORY = 100;

    public function __construct(private Connection $connection)
    {
    }

    public function insertTask(ScheduledTask $task): void
    {
        $this->connection->executeStatement(
            'INSERT INTO cms_scheduled_task (id, label, task_type, interval_seconds, timezone, status, next_run_at, created_at, updated_at) '
            .'VALUES (:id, :label, :task_type, :interval_seconds, :timezone, :status, :next_run_at, :created_at, :updated_at)',
            self::taskParameters($task),
        );
    }

    public function updateTask(ScheduledTask $task, \DateTimeImmutable $expectedUpdatedAt, \DateTimeImmutable $now): void
    {
        $parameters = [
            'id' => $task->id,
            'label' => $task->label,
            'task_type' => $task->type->value,
            'interval_seconds' => $task->frequency->intervalSeconds,
            'timezone' => $task->frequency->timezone,
            'status' => $task->status->value,
            'next_run_at' => null === $task->nextRunAt ? null : self::date($task->nextRunAt),
            'expected_updated_at' => self::date($expectedUpdatedAt),
            'now' => self::date($now),
        ];
        $updated = $this->connection->executeStatement(
            'UPDATE cms_scheduled_task SET label = :label, task_type = :task_type, interval_seconds = :interval_seconds, timezone = :timezone, '
            .'status = :status, next_run_at = :next_run_at, updated_at = :now '
            .'WHERE id = :id AND updated_at = :expected_updated_at',
            $parameters,
        );
        if (1 !== $updated) {
            throw new \DomainException('Scheduled task changed concurrently or is no longer available.');
        }
    }

    public function findTask(string $id): ?ScheduledTask
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM cms_scheduled_task WHERE id = :id', ['id' => $id]);

        return false === $row ? null : self::taskFromRow($row);
    }

    public function listTasks(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM cms_scheduled_task ORDER BY created_at DESC, id');

        return array_map(self::taskFromRow(...), $rows);
    }

    public function findDueTasks(\DateTimeImmutable $now, int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Task batch limit must be between 1 and 100.');
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cms_scheduled_task WHERE status = :status AND next_run_at <= :now ORDER BY next_run_at, id LIMIT :limit',
            ['status' => TaskStatus::Active->value, 'now' => self::date($now), 'limit' => $limit],
        );

        return array_map(self::taskFromRow(...), $rows);
    }

    public function abandonSupersededExecutions(\DateTimeImmutable $now): void
    {
        $this->connection->transactional(function (Connection $connection) use ($now): void {
            $selectParameters = [
                'now' => self::date($now),
                'running' => ExecutionState::Running->value,
                'active' => TaskStatus::Active->value,
            ];
            $taskIds = $connection->fetchFirstColumn(
                'SELECT DISTINCT run.schedule_id FROM cms_scheduled_task_run AS run '
                .'WHERE run.status = :running AND run.lease_expires_at <= :now '
                .'AND NOT EXISTS (SELECT 1 FROM cms_scheduled_task AS task WHERE task.id = run.schedule_id AND task.status = :active AND task.next_run_at = run.scheduled_for)',
                $selectParameters,
            );
            if ([] === $taskIds) {
                return;
            }

            $connection->executeStatement(
                'UPDATE cms_scheduled_task_run AS run SET status = :abandoned, result_code = :superseded, finished_at = :now, lease_token = NULL, lease_expires_at = NULL '
                .'WHERE run.status = :running AND run.lease_expires_at <= :now '
                .'AND NOT EXISTS (SELECT 1 FROM cms_scheduled_task AS task WHERE task.id = run.schedule_id AND task.status = :active AND task.next_run_at = run.scheduled_for)',
                $selectParameters + [
                    'abandoned' => ExecutionState::Abandoned->value,
                    'superseded' => ExecutionResultCode::Superseded->value,
                ],
            );
            foreach ($taskIds as $taskId) {
                if (!is_string($taskId)) {
                    throw new \UnexpectedValueException('Persisted scheduling task id is invalid.');
                }
                $this->pruneExecutionHistory($connection, $taskId);
            }
        });
    }

    public function deleteDisabledTask(string $id, \DateTimeImmutable $now): bool
    {
        $deleted = $this->connection->executeStatement(
            'DELETE FROM cms_scheduled_task AS task WHERE task.id = :id AND task.status = :disabled '
            .'AND NOT EXISTS (SELECT 1 FROM cms_scheduled_task_run AS run WHERE run.schedule_id = task.id AND run.status = :running AND run.lease_expires_at > :now)',
            ['id' => $id, 'disabled' => TaskStatus::Disabled->value, 'running' => ExecutionState::Running->value, 'now' => self::date($now)],
        );

        return 1 === $deleted;
    }

    public function hasUnexpiredExecution(string $taskId, \DateTimeImmutable $now): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM cms_scheduled_task_run WHERE schedule_id = :id AND status = :status AND lease_expires_at > :now LIMIT 1',
            ['id' => $taskId, 'status' => ExecutionState::Running->value, 'now' => self::date($now)],
        );
    }

    public function claimExecution(
        string $taskId,
        \DateTimeImmutable $scheduledFor,
        \DateTimeImmutable $now,
        \DateTimeImmutable $leaseExpiresAt,
        \DateTimeImmutable $nextRunAfterAttemptLimit,
        int $maximumAttempts,
    ): ?TaskExecutionClaim {
        if ($maximumAttempts < 1 || $maximumAttempts > SchedulingStore::MAXIMUM_ATTEMPTS || $leaseExpiresAt <= $now) {
            throw new \InvalidArgumentException('Task execution lease or retry limit is invalid.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($taskId, $scheduledFor, $now, $leaseExpiresAt, $nextRunAfterAttemptLimit, $maximumAttempts): ?TaskExecutionClaim {
            $taskIdParameters = [
                'task_id' => $taskId,
                'scheduled_for' => self::date($scheduledFor),
                'now' => self::date($now),
            ];
            $connection->executeStatement(
                'UPDATE cms_scheduled_task_run SET status = :abandoned, result_code = :attempt_limit_code, finished_at = :now, lease_token = NULL, lease_expires_at = NULL '
                .'WHERE schedule_id = :task_id AND scheduled_for = :scheduled_for AND status = :running AND lease_expires_at <= :now AND attempts >= :attempt_limit',
                $taskIdParameters + ['attempt_limit' => $maximumAttempts, 'abandoned' => ExecutionState::Abandoned->value, 'attempt_limit_code' => ExecutionResultCode::AttemptLimit->value, 'running' => ExecutionState::Running->value],
            );
            $connection->executeStatement(
                'UPDATE cms_scheduled_task AS task SET next_run_at = :next_run_at, updated_at = :now '
                .'WHERE task.id = :task_id AND task.status = :active AND task.next_run_at = :scheduled_for '
                .'AND EXISTS (SELECT 1 FROM cms_scheduled_task_run AS run WHERE run.schedule_id = task.id AND run.scheduled_for = :scheduled_for AND run.status = :abandoned)',
                $taskIdParameters + ['next_run_at' => self::date($nextRunAfterAttemptLimit), 'active' => TaskStatus::Active->value, 'abandoned' => ExecutionState::Abandoned->value],
            );

            $leaseToken = bin2hex(random_bytes(16));
            $inserted = $connection->executeStatement(
                'INSERT INTO cms_scheduled_task_run (schedule_id, scheduled_for, status, attempts, result_code, lease_token, lease_expires_at, started_at, finished_at) '
                .'SELECT task.id, task.next_run_at, :running, 1, NULL, :lease_token, :lease_expires_at, :now, NULL '
                .'FROM cms_scheduled_task AS task WHERE task.id = :task_id AND task.status = :active AND task.next_run_at = :scheduled_for AND task.next_run_at <= :now '
                .'ON CONFLICT (schedule_id, scheduled_for) DO UPDATE SET status = :running, attempts = cms_scheduled_task_run.attempts + 1, '
                .'result_code = NULL, lease_token = :lease_token, lease_expires_at = :lease_expires_at, started_at = :now, finished_at = NULL '
                .'WHERE cms_scheduled_task_run.status = :running AND cms_scheduled_task_run.lease_expires_at <= :now AND cms_scheduled_task_run.attempts < :attempt_limit',
                $taskIdParameters + [
                    'running' => ExecutionState::Running->value,
                    'lease_token' => $leaseToken,
                    'lease_expires_at' => self::date($leaseExpiresAt),
                    'active' => TaskStatus::Active->value,
                    'attempt_limit' => $maximumAttempts,
                ],
            );
            if (0 === $inserted) {
                return null;
            }

            $attempt = $connection->fetchOne(
                'SELECT attempts FROM cms_scheduled_task_run WHERE schedule_id = :task_id AND scheduled_for = :scheduled_for AND lease_token = :lease_token',
                ['task_id' => $taskId, 'scheduled_for' => self::date($scheduledFor), 'lease_token' => $leaseToken],
            );
            if (false === $attempt) {
                throw new \RuntimeException('Task execution claim could not be confirmed.');
            }

            $key = hash('sha256', $taskId.'|'.$scheduledFor->getTimestamp());

            return new TaskExecutionClaim($taskId, $scheduledFor, self::integer($attempt, 'attempts'), $leaseToken, $key);
        });
    }

    public function finishExecution(
        TaskExecutionClaim $claim,
        ExecutionState $state,
        ExecutionResultCode $resultCode,
        \DateTimeImmutable $finishedAt,
        \DateTimeImmutable $nextRunAt,
    ): ?TaskExecutionRecord {
        if (!in_array($state, [ExecutionState::Succeeded, ExecutionState::Failed], true)
            || (ExecutionState::Succeeded === $state && ExecutionResultCode::Completed !== $resultCode)
            || (ExecutionState::Failed === $state && ExecutionResultCode::Completed === $resultCode)
        ) {
            throw new \InvalidArgumentException('Finished task state and result code are inconsistent.');
        }

        return $this->connection->transactional(function (Connection $connection) use ($claim, $state, $resultCode, $finishedAt, $nextRunAt): ?TaskExecutionRecord {
            $parameters = [
                'task_id' => $claim->taskId,
                'scheduled_for' => self::date($claim->scheduledFor),
                'lease_token' => $claim->leaseToken,
                'state' => $state->value,
                'result_code' => $resultCode->value,
                'finished_at' => self::date($finishedAt),
            ];
            $updated = $connection->executeStatement(
                'UPDATE cms_scheduled_task_run SET status = :state, result_code = :result_code, finished_at = :finished_at, lease_token = NULL, lease_expires_at = NULL '
                .'WHERE schedule_id = :task_id AND scheduled_for = :scheduled_for AND status = :running AND lease_token = :lease_token AND lease_expires_at > :finished_at',
                $parameters + ['running' => ExecutionState::Running->value],
            );
            if (1 !== $updated) {
                return null;
            }

            $connection->executeStatement(
                'UPDATE cms_scheduled_task SET next_run_at = :next_run_at, updated_at = :finished_at '
                .'WHERE id = :task_id AND status = :active AND next_run_at = :scheduled_for',
                [
                    'task_id' => $claim->taskId,
                    'scheduled_for' => self::date($claim->scheduledFor),
                    'next_run_at' => self::date($nextRunAt),
                    'finished_at' => self::date($finishedAt),
                    'active' => TaskStatus::Active->value,
                ],
            );
            $this->pruneExecutionHistory($connection, $claim->taskId);

            $row = $connection->fetchAssociative(
                'SELECT * FROM cms_scheduled_task_run WHERE schedule_id = :task_id AND scheduled_for = :scheduled_for',
                ['task_id' => $claim->taskId, 'scheduled_for' => self::date($claim->scheduledFor)],
            );

            return false === $row ? null : self::executionFromRow($row);
        });
    }

    public function listExecutions(string $taskId, int $limit): array
    {
        if ($limit < 1 || $limit > self::MAXIMUM_EXECUTION_HISTORY) {
            throw new \InvalidArgumentException('Execution history limit is invalid.');
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM cms_scheduled_task_run WHERE schedule_id = :task_id ORDER BY scheduled_for DESC LIMIT :limit',
            ['task_id' => $taskId, 'limit' => $limit],
        );

        return array_map(self::executionFromRow(...), $rows);
    }

    private function pruneExecutionHistory(Connection $connection, string $taskId): void
    {
        $connection->executeStatement(
            'DELETE FROM cms_scheduled_task_run WHERE schedule_id = :task_id AND status <> :running '
            .'AND scheduled_for NOT IN (SELECT scheduled_for FROM cms_scheduled_task_run WHERE schedule_id = :task_id ORDER BY scheduled_for DESC LIMIT :history_limit)',
            ['task_id' => $taskId, 'running' => ExecutionState::Running->value, 'history_limit' => self::MAXIMUM_EXECUTION_HISTORY],
        );
    }

    /** @param array<string, mixed> $row */
    private static function taskFromRow(array $row): ScheduledTask
    {
        $typeValue = self::string($row, 'task_type');
        $type = TaskType::tryFrom($typeValue);
        $status = TaskStatus::tryFrom(self::string($row, 'status'));
        if (null === $type || null === $status) {
            throw new \UnexpectedValueException('Persisted scheduled task contains an unsupported type or status.');
        }
        $nextRunAt = self::nullableDate($row, 'next_run_at');

        return new ScheduledTask(
            self::string($row, 'id'),
            self::string($row, 'label'),
            $type,
            ScheduleFrequency::fromInput(self::integer($row['interval_seconds'] ?? null, 'interval_seconds'), self::string($row, 'timezone')),
            $status,
            $nextRunAt,
            self::dateColumn($row, 'created_at'),
            self::dateColumn($row, 'updated_at'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function executionFromRow(array $row): TaskExecutionRecord
    {
        $state = ExecutionState::tryFrom(self::string($row, 'status'));
        if (null === $state) {
            throw new \UnexpectedValueException('Persisted task execution contains an unsupported state.');
        }
        $resultValue = $row['result_code'] ?? null;
        $result = null === $resultValue ? null : ExecutionResultCode::tryFrom(self::string($row, 'result_code'));
        if (null !== $resultValue && null === $result) {
            throw new \UnexpectedValueException('Persisted task execution contains an unsupported result code.');
        }

        return new TaskExecutionRecord(
            self::string($row, 'schedule_id'),
            self::dateColumn($row, 'scheduled_for'),
            $state,
            self::integer($row['attempts'] ?? null, 'attempts'),
            $result,
            self::dateColumn($row, 'started_at'),
            self::nullableDate($row, 'finished_at'),
            self::nullableDate($row, 'lease_expires_at'),
        );
    }

    /** @return array<string, mixed> */
    private static function taskParameters(ScheduledTask $task): array
    {
        return [
            'id' => $task->id,
            'label' => $task->label,
            'task_type' => $task->type->value,
            'interval_seconds' => $task->frequency->intervalSeconds,
            'timezone' => $task->frequency->timezone,
            'status' => $task->status->value,
            'next_run_at' => null === $task->nextRunAt ? null : self::date($task->nextRunAt),
            'created_at' => self::date($task->createdAt),
            'updated_at' => self::date($task->updatedAt),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Persisted scheduling column %s is invalid.', $column));
        }

        return $value;
    }

    private static function integer(mixed $value, string $column): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && 1 === preg_match('/\A[0-9]+\z/D', $value)) {
            return (int) $value;
        }

        throw new \UnexpectedValueException(sprintf('Persisted scheduling column %s is invalid.', $column));
    }

    /** @param array<string, mixed> $row */
    private static function dateColumn(array $row, string $column): \DateTimeImmutable
    {
        $value = $row[$column] ?? null;
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (is_string($value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception $exception) {
                throw new \UnexpectedValueException(sprintf('Persisted scheduling column %s is invalid.', $column), previous: $exception);
            }
        }

        throw new \UnexpectedValueException(sprintf('Persisted scheduling column %s is invalid.', $column));
    }

    /** @param array<string, mixed> $row */
    private static function nullableDate(array $row, string $column): ?\DateTimeImmutable
    {
        if (null === ($row[$column] ?? null)) {
            return null;
        }

        return self::dateColumn($row, $column);
    }

    private static function date(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }
}
