<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskType;

interface ScheduledTaskHandlerInterface
{
    public function type(): ScheduledTaskType;

    public function execute(ScheduledTask $task, \DateTimeImmutable $now): string;
}
