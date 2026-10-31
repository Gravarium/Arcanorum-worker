<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use App\Entity\ScheduledTaskType;

final readonly class ScheduledTaskHandlerRegistry
{
    /** @var array<string, ScheduledTaskHandlerInterface> */
    private array $handlers;

    /** @param iterable<ScheduledTaskHandlerInterface> $handlers */
    public function __construct(#[\Symfony\Component\DependencyInjection\Attribute\AutowireIterator('app.scheduled_task_handler')] iterable $handlers)
    {
        $indexed = [];
        foreach ($handlers as $handler) {
            $key = $handler->type()->value;
            if (isset($indexed[$key])) {
                throw new \LogicException('Für einen Aufgabentyp ist mehr als ein Handler registriert.');
            }
            $indexed[$key] = $handler;
        }
        $this->handlers = $indexed;
    }

    public function get(ScheduledTaskType $type): ScheduledTaskHandlerInterface
    {
        $handler = $this->handlers[$type->value] ?? null;
        if (!$handler instanceof ScheduledTaskHandlerInterface) {
            throw new \LogicException('Für diesen Aufgabentyp ist kein sicherer Handler vorhanden.');
        }

        return $handler;
    }
}
