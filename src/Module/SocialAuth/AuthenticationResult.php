<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

final readonly class AuthenticationResult
{
    public function __construct(public string $accountId, public Provider $provider, public Flow $flow)
    {
    }
}
