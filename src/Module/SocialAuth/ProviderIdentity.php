<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

final readonly class ProviderIdentity
{
    public function __construct(public Provider $provider, public string $subject, public ?VerifiedEmail $verifiedEmail)
    {
        if (
            '' === $subject
            || strlen($subject) > 255
            || trim($subject) !== $subject
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $subject)
        ) {
            throw new \InvalidArgumentException('Die Anbieteridentitaet ist ungueltig.');
        }
    }
}
