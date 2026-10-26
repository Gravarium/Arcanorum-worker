<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

interface SchedulingClock
{
    public function now(): \DateTimeImmutable;
}
