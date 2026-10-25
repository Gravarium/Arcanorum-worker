<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

use InvalidArgumentException;

final readonly class ProviderIdentity
{
    public function __construct(public Provider $provider, public string $subject, public ?VerifiedEmail $verifiedEmail)
    {
        if (
            $subject === ''
            || strlen($subject) > 255
            || trim($subject) !== $subject
            || preg_match('/[\x00-\x1F\x7F]/', $subject) === 1
        ) {
            throw new InvalidArgumentException('Die Anbieteridentitaet ist ungueltig.');
        }
    }
}
