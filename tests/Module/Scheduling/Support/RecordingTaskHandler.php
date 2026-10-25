<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Support;

use App\Module\Scheduling\Application\ScheduledTaskHandler;
use App\Module\Scheduling\Application\TaskExecutionContext;
use App\Module\Scheduling\Domain\TaskType;

final class RecordingTaskHandler implements ScheduledTaskHandler
{
    /** @var list<string> */
    public array $idempotencyKeys = [];

    public function __construct(private readonly TaskType $taskType, private readonly bool $shouldFail = false)
    {
    }

    public function type(): TaskType
    {
        return $this->taskType;
    }

    public function execute(TaskExecutionContext $context): void
    {
        $this->idempotencyKeys[] = $context->idempotencyKey;
        if ($this->shouldFail) {
            throw new \RuntimeException('A private handler message must never be persisted.');
        }
    }
}
