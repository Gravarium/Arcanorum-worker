<?php

declare(strict_types=1);

namespace App\Tests\Module\SocialAuth\Support;

use App\Module\SocialAuth\AuthenticationRateLimiter;
use App\Module\SocialAuth\Provider;

final class FakeRateLimiter implements AuthenticationRateLimiter
{
    /** @var list<array{Provider, string, ?string}> */
    public array $calls = [];

    public bool $allowed = true;

    public function consume(Provider $provider, string $operation, ?string $accountKey): bool
    {
        $this->calls[] = [$provider, $operation, $accountKey];

        return $this->allowed;
    }
}
