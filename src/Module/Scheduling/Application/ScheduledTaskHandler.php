<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\TaskType;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('cms.scheduling.task_handler')]
interface ScheduledTaskHandler
{
    public function type(): TaskType;

    public function execute(TaskExecutionContext $context): void;
}
