<?php

declare(strict_types=1);

namespace App\Platform\Security;

final readonly class AdminSessionLifetime
{
    public const int IDLE_TIMEOUT_SECONDS = 1800;
    public const int ABSOLUTE_TIMEOUT_SECONDS = 28800;

    public function isExpired(?int $issuedAt, ?int $lastActivityAt, int $now): bool
    {
        if (null === $issuedAt || null === $lastActivityAt) {
            return true;
        }

        if (
            $issuedAt <= 0
            || $lastActivityAt <= 0
            || $lastActivityAt < $issuedAt
            || $issuedAt > $now
            || $lastActivityAt > $now
        ) {
            return true;
        }

        return $now - $issuedAt >= self::ABSOLUTE_TIMEOUT_SECONDS
            || $now - $lastActivityAt >= self::IDLE_TIMEOUT_SECONDS;
    }
}
