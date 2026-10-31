<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

use App\Entity\ScheduledTask;

interface ScheduledTaskStore
{
    /** @return list<ScheduledTask> */
    public function findDue(\DateTimeImmutable $now, int $limit = 10): array;

    public function findByTaskKey(string $taskKey): ?ScheduledTask;
}
