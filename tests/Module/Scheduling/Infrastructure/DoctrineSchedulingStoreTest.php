<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Infrastructure;

use App\Module\Scheduling\Domain\ExecutionResultCode;
use App\Module\Scheduling\Domain\ExecutionState;
use App\Module\Scheduling\Domain\ScheduleFrequency;
use App\Module\Scheduling\Domain\ScheduledTask;
use App\Module\Scheduling\Domain\TaskType;
use App\Module\Scheduling\Infrastructure\DoctrineSchedulingStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineSchedulingStoreTest extends KernelTestCase
{
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->createTemporarySchema($connection);
    }

    public function testTaskPersistsAndExecutionClaimsAreAtomicAndIdempotent(): void
    {
        $store = new DoctrineSchedulingStore($this->connection());
        $createdAt = new \DateTimeImmutable('2026-10-25T12:00:00+00:00');
        $frequency = ScheduleFrequency::fromInput(3600, 'Europe/Berlin');
        $task = ScheduledTask::create(str_repeat('a', 32), 'Cleanup', TaskType::CacheCleanup, $frequency, $createdAt)->activate($createdAt);
        $store->insertTask($task);

        $loaded = $store->findTask($task->id);
        self::assertNotNull($loaded);
        self::assertSame($task->id, $loaded->id);
        self::assertSame(TaskType::CacheCleanup, $loaded->type);
        self::assertSame('Europe/Berlin', $loaded->frequency->timezone);
        $due = $task->nextRunAt;
        self::assertNotNull($due);
        self::assertCount(1, $store->findDueTasks($due, 10));
        $first = $store->claimExecution($task->id, $due, $due, $due->modify('+120 seconds'), $frequency->nextAfter($due), 10);
        self::assertNotNull($first);
        self::assertNull($store->claimExecution($task->id, $due, $due->modify('+1 second'), $due->modify('+121 seconds'), $frequency->nextAfter($due), 10));

        $finishedAt = $due->modify('+5 seconds');
        $record = $store->finishExecution($first, ExecutionState::Succeeded, ExecutionResultCode::Completed, $finishedAt, $frequency->nextAfter($due));
        self::assertNotNull($record);
        self::assertSame(ExecutionState::Succeeded, $record->state);
        self::assertSame(ExecutionResultCode::Completed, $record->resultCode);
        self::assertCount(1, $store->listExecutions($task->id, 10));
        $updatedTask = $store->findTask($task->id);
        self::assertNotNull($updatedTask);
        self::assertNotNull($updatedTask->nextRunAt);
        self::assertSame($frequency->nextAfter($due)->getTimestamp(), $updatedTask->nextRunAt->getTimestamp());
    }

    public function testExpiredLeaseCanBeReclaimedButOldWorkerCannotCompleteIt(): void
    {
        $store = new DoctrineSchedulingStore($this->connection());
        $createdAt = new \DateTimeImmutable('2026-10-25T12:00:00+00:00');
        $frequency = ScheduleFrequency::fromInput(3600, 'UTC');
        $task = ScheduledTask::create(str_repeat('b', 32), 'Refresh', TaskType::PublicIndexRefresh, $frequency, $createdAt)->activate($createdAt);
        $store->insertTask($task);
        $due = $task->nextRunAt;
        self::assertNotNull($due);
        $first = $store->claimExecution($task->id, $due, $due, $due->modify('+120 seconds'), $frequency->nextAfter($due), 10);
        self::assertNotNull($first);

        $retryAt = $due->modify('+121 seconds');
        self::assertNull($store->finishExecution($first, ExecutionState::Succeeded, ExecutionResultCode::Completed, $retryAt, $frequency->nextAfter($retryAt)));
        $second = $store->claimExecution($task->id, $due, $retryAt, $retryAt->modify('+120 seconds'), $frequency->nextAfter($retryAt), 10);
        self::assertNotNull($second);
        self::assertSame(2, $second->attempt);
        self::assertSame($first->idempotencyKey, $second->idempotencyKey);
        self::assertNull($store->finishExecution($first, ExecutionState::Succeeded, ExecutionResultCode::Completed, $retryAt, $frequency->nextAfter($retryAt)));
        self::assertNotNull($store->finishExecution($second, ExecutionState::Failed, ExecutionResultCode::HandlerFailed, $retryAt, $frequency->nextAfter($retryAt)));
    }

    public function testExpiredExecutionFromSupersededScheduleIsAbandoned(): void
    {
        $store = new DoctrineSchedulingStore($this->connection());
        $createdAt = new \DateTimeImmutable('2026-10-25T12:00:00+00:00');
        $frequency = ScheduleFrequency::fromInput(3600, 'UTC');
        $task = ScheduledTask::create(str_repeat('c', 32), 'Refresh', TaskType::PublicIndexRefresh, $frequency, $createdAt)->activate($createdAt);
        $store->insertTask($task);
        $due = $task->nextRunAt;
        self::assertNotNull($due);
        $claim = $store->claimExecution($task->id, $due, $due, $due->modify('+120 seconds'), $frequency->nextAfter($due), 10);
        self::assertNotNull($claim);

        $pausedAt = $due->modify('+30 seconds');
        $paused = $task->pause($pausedAt);
        $store->updateTask($paused, $task->updatedAt, $pausedAt);
        $resumedAt = $due->modify('+121 seconds');
        $resumed = $paused->activate($resumedAt);
        $store->updateTask($resumed, $paused->updatedAt, $resumedAt);

        $store->abandonSupersededExecutions($resumedAt);

        $record = $store->listExecutions($task->id, 10)[0];
        self::assertSame(ExecutionState::Abandoned, $record->state);
        self::assertSame(ExecutionResultCode::Superseded, $record->resultCode);
        self::assertNull($record->leaseExpiresAt);
        self::assertNotNull($record->finishedAt);
        self::assertNull($store->finishExecution($claim, ExecutionState::Succeeded, ExecutionResultCode::Completed, $resumedAt, $frequency->nextAfter($resumedAt)));
    }

    public function testAttemptLimitCannotExceedSchemaMaximum(): void
    {
        $store = new DoctrineSchedulingStore($this->connection());
        $createdAt = new \DateTimeImmutable('2026-10-25T12:00:00+00:00');
        $frequency = ScheduleFrequency::fromInput(3600, 'UTC');
        $task = ScheduledTask::create(str_repeat('d', 32), 'Cleanup', TaskType::CacheCleanup, $frequency, $createdAt)->activate($createdAt);
        $store->insertTask($task);
        $due = $task->nextRunAt;
        self::assertNotNull($due);

        $this->expectException(\InvalidArgumentException::class);
        $store->claimExecution($task->id, $due, $due, $due->modify('+120 seconds'), $frequency->nextAfter($due), 11);
    }

    public function testExecutionHistoryRemainsBounded(): void
    {
        $store = new DoctrineSchedulingStore($this->connection());
        $createdAt = new \DateTimeImmutable('2026-10-25T12:00:00+00:00');
        $frequency = ScheduleFrequency::fromInput(60, 'UTC');
        $task = ScheduledTask::create(str_repeat('e', 32), 'Cleanup', TaskType::CacheCleanup, $frequency, $createdAt)->activate($createdAt);
        $store->insertTask($task);
        $firstDue = $task->nextRunAt;
        self::assertNotNull($firstDue);
        $due = $firstDue;

        for ($iteration = 0; $iteration < 101; ++$iteration) {
            $claim = $store->claimExecution($task->id, $due, $due, $due->modify('+120 seconds'), $frequency->nextAfter($due), 10);
            self::assertNotNull($claim);
            self::assertNotNull($store->finishExecution($claim, ExecutionState::Succeeded, ExecutionResultCode::Completed, $due->modify('+1 second'), $frequency->nextAfter($due)));
            $due = $frequency->nextAfter($due);
        }

        self::assertCount(100, $store->listExecutions($task->id, 100));
        $storedCount = $this->connection()->fetchOne('SELECT COUNT(*) FROM cms_scheduled_task_run WHERE schedule_id = :id', ['id' => $task->id]);
        self::assertContains($storedCount, [100, '100']);
        self::assertSame($frequency->nextAfter($firstDue)->getTimestamp(), $store->listExecutions($task->id, 100)[99]->scheduledFor->getTimestamp());
    }

    private function connection(): Connection
    {
        return $this->connection ?? throw new \LogicException('The database connection is not available.');
    }

    private function createTemporarySchema(Connection $connection): void
    {
        $connection->executeStatement("CREATE TEMPORARY TABLE IF NOT EXISTS cms_scheduled_task (id char(32) PRIMARY KEY, label varchar(120) NOT NULL, task_type varchar(32) NOT NULL CHECK (task_type IN ('cache_cleanup', 'public_index_refresh')), interval_seconds integer NOT NULL CHECK (interval_seconds BETWEEN 60 AND 2592000), timezone varchar(64) NOT NULL, status varchar(16) NOT NULL CHECK (status IN ('active', 'paused', 'disabled')), next_run_at timestamptz NULL, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL, CHECK ((status = 'active' AND next_run_at IS NOT NULL) OR (status <> 'active' AND next_run_at IS NULL)))");
        $connection->executeStatement("CREATE TEMPORARY TABLE IF NOT EXISTS cms_scheduled_task_run (schedule_id char(32) NOT NULL REFERENCES cms_scheduled_task (id) ON DELETE CASCADE, scheduled_for timestamptz NOT NULL, status varchar(16) NOT NULL CHECK (status IN ('running', 'succeeded', 'failed', 'abandoned')), attempts integer NOT NULL CHECK (attempts BETWEEN 1 AND 10), result_code varchar(32) NULL CHECK (result_code IS NULL OR result_code IN ('completed', 'handler_failed', 'handler_unavailable', 'attempt_limit', 'superseded')), lease_token char(32) NULL, lease_expires_at timestamptz NULL, started_at timestamptz NOT NULL, finished_at timestamptz NULL, PRIMARY KEY (schedule_id, scheduled_for), CHECK ((status = 'running' AND result_code IS NULL AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL AND finished_at IS NULL) OR (status <> 'running' AND result_code IS NOT NULL AND lease_token IS NULL AND lease_expires_at IS NULL AND finished_at IS NOT NULL)))");
        $connection->executeStatement('TRUNCATE cms_scheduled_task_run, cms_scheduled_task');
    }
}
