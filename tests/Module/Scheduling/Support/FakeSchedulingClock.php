<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Support;

use App\Module\Scheduling\Application\SchedulingClock;

final class FakeSchedulingClock implements SchedulingClock
{
    public function __construct(public \DateTimeImmutable $currentTime)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->currentTime;
    }

    public function advance(string $interval): void
    {
        $this->currentTime = $this->currentTime->modify($interval);
    }
}
