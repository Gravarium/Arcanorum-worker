<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

use DateTimeImmutable;

final readonly class AuthorizationRequest
{
    public function __construct(public Provider $provider, public string $authorizationUrl, public DateTimeImmutable $expiresAt)
    {
    }
}
