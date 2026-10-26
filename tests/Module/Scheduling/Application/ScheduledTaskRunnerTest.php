<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Application;

use App\Module\Scheduling\Application\ScheduledTaskHandler;
use App\Module\Scheduling\Application\ScheduledTaskRunner;
use App\Module\Scheduling\Application\TaskExecutionContext;
use App\Module\Scheduling\Application\TaskHandlerRegistry;
use App\Module\Scheduling\Domain\ExecutionResultCode;
use App\Module\Scheduling\Domain\ExecutionState;
use App\Module\Scheduling\Domain\ScheduledTask;
use App\Module\Scheduling\Domain\ScheduleFrequency;
use App\Module\Scheduling\Domain\TaskStatus;
use App\Module\Scheduling\Domain\TaskType;
use App\Tests\Module\Scheduling\Support\FakeSchedulingClock;
use App\Tests\Module\Scheduling\Support\InMemorySchedulingStore;
use App\Tests\Module\Scheduling\Support\RecordingTaskHandler;
use PHPUnit\Framework\TestCase;

final class ScheduledTaskRunnerTest extends TestCase
{
    public function testDueTaskRunsOnceAndAdvancesItsNextOccurrence(): void
    {
        $store = new InMemorySchedulingStore();
        $task = $this->activeTask($store);
        $due = $task->nextRunAt;
        self::assertNotNull($due);
        $handler = new RecordingTaskHandler(TaskType::CacheCleanup);
        $runner = new ScheduledTaskRunner($store, new TaskHandlerRegistry([$handler]), new FakeSchedulingClock($due));

        self::assertSame(1, $runner->runDue($due));
        self::assertSame(0, $runner->runDue($due));
        self::assertCount(1, $handler->idempotencyKeys);
        $updatedTask = $store->findTask($task->id);
        self::assertNotNull($updatedTask);
        self::assertSame(TaskStatus::Active, $updatedTask->status);
        self::assertNotNull($updatedTask->nextRunAt);
        self::assertSame($task->frequency->nextAfter($due)->getTimestamp(), $updatedTask->nextRunAt->getTimestamp());

        $records = $store->listExecutions($task->id, 10);
        self::assertCount(1, $records);
        self::assertSame(ExecutionState::Succeeded, $records[0]->state);
        self::assertSame(ExecutionResultCode::Completed, $records[0]->resultCode);
    }

    public function testExpiredLeaseCanResumeWithSameIdempotencyKeyAndStaleOwnerCannotFinish(): void
    {
        $store = new InMemorySchedulingStore();
        $task = $this->activeTask($store);
        $due = $task->nextRunAt;
        self::assertNotNull($due);
        $firstClaim = $store->claimExecution($task->id, $due, $due, $due->modify('+120 seconds'), $task->frequency->nextAfter($due), 10);
        self::assertNotNull($firstClaim);
        $handler = new RecordingTaskHandler(TaskType::CacheCleanup);
        $handler->execute(TaskExecutionContext::fromClaim($firstClaim));

        $retryAt = $due->modify('+121 seconds');
        self::assertNull($store->finishExecution($firstClaim, ExecutionState::Succeeded, ExecutionResultCode::Completed, $retryAt, $task->frequency->nextAfter($retryAt)));
        $runner = new ScheduledTaskRunner($store, new TaskHandlerRegistry([$handler]), new FakeSchedulingClock($retryAt));
        self::assertSame(1, $runner->runDue($retryAt));
        self::assertCount(2, $handler->idempotencyKeys);
        self::assertSame($handler->idempotencyKeys[0], $handler->idempotencyKeys[1]);
        self::assertNull($store->finishExecution($firstClaim, ExecutionState::Succeeded, ExecutionResultCode::Completed, $retryAt, $task->frequency->nextAfter($retryAt)));
        self::assertSame(2, $store->listExecutions($task->id, 10)[0]->attempt);
    }

    public function testHandlerFailuresAreRecordedWithoutExceptionDetailsAndScheduleContinues(): void
    {
        $store = new InMemorySchedulingStore();
        $task = $this->activeTask($store);
        $due = $task->nextRunAt;
        self::assertNotNull($due);
        $runner = new ScheduledTaskRunner($store, new TaskHandlerRegistry([new RecordingTaskHandler(TaskType::CacheCleanup, true)]), new FakeSchedulingClock($due));

        self::assertSame(1, $runner->runDue($due));
        $record = $store->listExecutions($task->id, 10)[0];
        self::assertSame(ExecutionState::Failed, $record->state);
        self::assertSame(ExecutionResultCode::HandlerFailed, $record->resultCode);
        $updatedTask = $store->findTask($task->id);
        self::assertNotNull($updatedTask);
        self::assertNotNull($updatedTask->nextRunAt);
        self::assertSame($task->frequency->nextAfter($due)->getTimestamp(), $updatedTask->nextRunAt->getTimestamp());
        self::assertObjectNotHasProperty('message', $record);
    }

    public function testUnexpiredLeasePreventsParallelExecution(): void
    {
        $store = new InMemorySchedulingStore();
        $task = $this->activeTask($store);
        $due = $task->nextRunAt;
        self::assertNotNull($due);
        self::assertNotNull($store->claimExecution($task->id, $due, $due, $due->modify('+120 seconds'), $task->frequency->nextAfter($due), 10));
        $handler = new RecordingTaskHandler(TaskType::CacheCleanup);
        $runner = new ScheduledTaskRunner($store, new TaskHandlerRegistry([$handler]), new FakeSchedulingClock($due->modify('+60 seconds')));

        self::assertSame(0, $runner->runDue($due->modify('+60 seconds')));
        self::assertSame([], $handler->idempotencyKeys);
    }

    public function testRunnerRejectsAHandlerThatFinishesAfterItsLeaseExpires(): void
    {
        $store = new InMemorySchedulingStore();
        $task = $this->activeTask($store);
        $due = $task->nextRunAt;
        self::assertNotNull($due);
        $clock = new FakeSchedulingClock($due);
        $handler = new class($clock) implements ScheduledTaskHandler {
            public function __construct(private readonly FakeSchedulingClock $clock)
            {
            }

            public function type(): TaskType
            {
                return TaskType::CacheCleanup;
            }

            public function execute(TaskExecutionContext $context): void
            {
                $this->clock->advance('+121 seconds');
            }
        };
        $runner = new ScheduledTaskRunner($store, new TaskHandlerRegistry([$handler]), $clock);

        try {
            $runner->runDue($due);
        } catch (\RuntimeException $exception) {
            self::assertSame('Task execution lease is no longer current.', $exception->getMessage());
            $record = $store->listExecutions($task->id, 10)[0];
            self::assertSame(ExecutionState::Running, $record->state);
            self::assertSame(1, $record->attempt);

            return;
        }

        self::fail('The runner accepted a completion after the lease expired.');
    }

    public function testExpiredLeaseForPausedAndResumedScheduleIsRecordedAsSuperseded(): void
    {
        $store = new InMemorySchedulingStore();
        $task = $this->activeTask($store);
        $due = $task->nextRunAt;
        self::assertNotNull($due);
        $oldClaim = $store->claimExecution($task->id, $due, $due, $due->modify('+120 seconds'), $task->frequency->nextAfter($due), 10);
        self::assertNotNull($oldClaim);

        $pausedAt = $due->modify('+30 seconds');
        $paused = $task->pause($pausedAt);
        $store->updateTask($paused, $task->updatedAt, $pausedAt);
        $resumedAt = $due->modify('+121 seconds');
        $resumed = $paused->activate($resumedAt);
        $store->updateTask($resumed, $paused->updatedAt, $resumedAt);
        $runner = new ScheduledTaskRunner($store, new TaskHandlerRegistry([new RecordingTaskHandler(TaskType::CacheCleanup)]), new FakeSchedulingClock($resumedAt));

        self::assertSame(0, $runner->runDue($resumedAt));
        $record = $store->listExecutions($task->id, 10)[0];
        self::assertSame(ExecutionState::Abandoned, $record->state);
        self::assertSame(ExecutionResultCode::Superseded, $record->resultCode);
        self::assertNull($record->leaseExpiresAt);
        self::assertNull($store->finishExecution($oldClaim, ExecutionState::Succeeded, ExecutionResultCode::Completed, $resumedAt, $task->frequency->nextAfter($resumedAt)));
    }

    public function testMissingHandlerFailsClosedAndAttemptLimitAbandonsAnExpiredRun(): void
    {
        $store = new InMemorySchedulingStore();
        $task = $this->activeTask($store);
        $runner = new ScheduledTaskRunner($store, new TaskHandlerRegistry([]), new FakeSchedulingClock($due));
        $due = $task->nextRunAt;
        self::assertNotNull($due);
        self::assertSame(1, $runner->runDue($due));
        self::assertSame(ExecutionResultCode::HandlerUnavailable, $store->listExecutions($task->id, 10)[0]->resultCode);

        $retryStore = new InMemorySchedulingStore();
        $retryTask = $this->activeTask($retryStore, str_repeat('b', 32));
        $scheduledFor = $retryTask->nextRunAt;
        self::assertNotNull($scheduledFor);
        $attemptNow = $scheduledFor;
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $claim = $retryStore->claimExecution(
                $retryTask->id,
                $scheduledFor,
                $attemptNow,
                $attemptNow->modify('+120 seconds'),
                $retryTask->frequency->nextAfter($attemptNow),
                10,
            );
            self::assertNotNull($claim);
            self::assertSame($attempt, $claim->attempt);
            $attemptNow = $attemptNow->modify('+120 seconds');
        }

        self::assertNull($retryStore->claimExecution(
            $retryTask->id,
            $scheduledFor,
            $attemptNow,
            $attemptNow->modify('+120 seconds'),
            $retryTask->frequency->nextAfter($attemptNow),
            10,
        ));
        self::assertSame(ExecutionState::Abandoned, $retryStore->listExecutions($retryTask->id, 10)[0]->state);
        $afterLimitTask = $retryStore->findTask($retryTask->id);
        self::assertNotNull($afterLimitTask);
        self::assertNotNull($afterLimitTask->nextRunAt);
        self::assertGreaterThan($attemptNow->getTimestamp(), $afterLimitTask->nextRunAt->getTimestamp());
    }

    private function activeTask(InMemorySchedulingStore $store, string $id = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): ScheduledTask
    {
        $at = new \DateTimeImmutable('2026-10-25T12:00:00+00:00');
        $task = ScheduledTask::create($id, 'Cleanup', TaskType::CacheCleanup, ScheduleFrequency::fromInput(60, 'UTC'), $at)->activate($at);
        $store->insertTask($task);

        return $task;
    }
}
