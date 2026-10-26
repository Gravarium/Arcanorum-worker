<?php

declare(strict_types=1);

namespace App\Tests\Module\SocialAuth\Support;

use App\Module\SocialAuth\Clock;

final class FakeClock implements Clock
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
