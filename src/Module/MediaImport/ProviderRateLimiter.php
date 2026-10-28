<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

use DateTimeImmutable;

interface ProviderRateLimiter
{
    /**
     * Atomically acquire a provider permit. Return null when acquired; otherwise
     * return the earliest safe retry time. Implementations must share state across
     * workers and respect provider quota/Retry-After information.
     */
    public function retryAt(MediaProvider $provider, DateTimeImmutable $now): ?DateTimeImmutable;
}
