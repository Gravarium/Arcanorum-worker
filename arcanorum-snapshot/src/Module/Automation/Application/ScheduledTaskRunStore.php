<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskRun;

interface ScheduledTaskRunStore
{
    public function findExistingForDueTime(ScheduledTask $task, \DateTimeImmutable $scheduledFor): ?ScheduledTaskRun;

    public function findRunById(string $id): ?ScheduledTaskRun;

    public function pruneHistory(int $maximumPerTask): int;
}
