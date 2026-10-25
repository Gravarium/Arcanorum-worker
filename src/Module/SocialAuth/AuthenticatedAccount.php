<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AuthenticatedAccount
{
    public function __construct(public string $accountId, public DateTimeImmutable $lastReauthenticatedAt)
    {
        if (
            $accountId === ''
            || strlen($accountId) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $accountId) === 1
        ) {
            throw new InvalidArgumentException('Der Kontokontext ist ungueltig.');
        }
    }
}
