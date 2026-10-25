<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\ExecutionResultCode;
use App\Module\Scheduling\Domain\ExecutionState;
use App\Module\Scheduling\Domain\TaskExecutionClaim;

final readonly class ScheduledTaskRunner
{
    private const int BATCH_LIMIT = 100;
    private const int LEASE_SECONDS = 120;

    public function __construct(private SchedulingStore $store, private TaskHandlerRegistry $handlers)
    {
    }

    public function runDue(\DateTimeImmutable $now): int
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $this->store->abandonSupersededExecutions($now);
        $dueTasks = $this->store->findDueTasks($now, self::BATCH_LIMIT);
        $processed = 0;

        foreach ($dueTasks as $task) {
            $scheduledFor = $task->nextRunAt;
            if (null === $scheduledFor) {
                continue;
            }

            $claim = $this->store->claimExecution(
                $task->id,
                $scheduledFor,
                $now,
                new \DateTimeImmutable('@'.($now->getTimestamp() + self::LEASE_SECONDS)),
                $task->frequency->nextAfter($now),
                SchedulingStore::MAXIMUM_ATTEMPTS,
            );
            if (null === $claim) {
                continue;
            }

            [$state, $result] = $this->execute($task->type, $claim);
            $record = $this->store->finishExecution(
                $claim,
                $state,
                $result,
                $now,
                $task->frequency->nextAfter($now),
            );
            if (null === $record) {
                throw new \RuntimeException('Task execution lease is no longer current.');
            }
            ++$processed;
        }

        return $processed;
    }

    /** @return array{ExecutionState, ExecutionResultCode} */
    private function execute(\App\Module\Scheduling\Domain\TaskType $type, TaskExecutionClaim $claim): array
    {
        $handler = $this->handlers->get($type);
        if (null === $handler) {
            return [ExecutionState::Failed, ExecutionResultCode::HandlerUnavailable];
        }

        try {
            $handler->execute(TaskExecutionContext::fromClaim($claim));
        } catch (\Throwable) {
            return [ExecutionState::Failed, ExecutionResultCode::HandlerFailed];
        }

        return [ExecutionState::Succeeded, ExecutionResultCode::Completed];
    }
}
