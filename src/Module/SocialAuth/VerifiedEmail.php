<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

final readonly class VerifiedEmail
{
    private function __construct(public string $value)
    {
    }

    public static function fromProvider(string $email, bool $verified): ?self
    {
        if (!$verified) {
            return null;
        }

        if (
            '' === $email
            || strlen($email) > 254
            || 1 === preg_match('/[\x00-\x20\x7F]/', $email)
            || false === filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            throw new \InvalidArgumentException('Die bestaetigte E-Mail-Adresse ist ungueltig.');
        }

        return new self($email);
    }
}
