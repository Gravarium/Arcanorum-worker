<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\TaskType;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class TaskHandlerRegistry
{
    /** @var array<string, ScheduledTaskHandler> */
    private array $handlers;

    /** @param iterable<ScheduledTaskHandler> $handlers */
    public function __construct(#[AutowireIterator('cms.scheduling.task_handler')] iterable $handlers)
    {
        $registered = [];
        foreach ($handlers as $handler) {
            $type = $handler->type();
            if (isset($registered[$type->value])) {
                throw new \InvalidArgumentException('A task type may have only one handler.');
            }
            $registered[$type->value] = $handler;
        }

        $this->handlers = $registered;
    }

    public function has(TaskType $type): bool
    {
        return isset($this->handlers[$type->value]);
    }

    public function get(TaskType $type): ?ScheduledTaskHandler
    {
        return $this->handlers[$type->value] ?? null;
    }
}
