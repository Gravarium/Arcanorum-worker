<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

interface AuthenticationRateLimiter
{
    /** The account key is a digest; the host may apply request context transiently. */
    public function consume(Provider $provider, string $operation, ?string $accountKey): bool;
}
