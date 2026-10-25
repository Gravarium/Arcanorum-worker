<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

final readonly class AuthenticatedAccount
{
    public function __construct(public string $accountId, public \DateTimeImmutable $lastReauthenticatedAt)
    {
        if (
            '' === $accountId
            || strlen($accountId) > 255
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $accountId)
        ) {
            throw new \InvalidArgumentException('Der Kontokontext ist ungueltig.');
        }
    }
}
