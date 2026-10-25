<?php

declare(strict_types=1);

namespace App\Tests\Module\SocialAuth\Support;

use App\Module\SocialAuth\Clock;
use DateTimeImmutable;

final class FakeClock implements Clock
{
    public function __construct(public DateTimeImmutable $currentTime)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->currentTime;
    }

    public function advance(string $interval): void
    {
        $next = $this->currentTime->modify($interval);
        if ($next === false) {
            throw new \InvalidArgumentException('Invalid test clock interval.');
        }

        $this->currentTime = $next;
    }
}
