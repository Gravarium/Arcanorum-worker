<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

final readonly class SocialOAuthProfile
{
    public function __construct(
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public string $displayName,
    ) {
        if ('' === $subject
            || strlen($subject) > 255
            || 1 !== preg_match('//u', $subject)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $subject)
            || (null !== $email && (mb_strlen($email, 'UTF-8') > 254 || false === filter_var($email, FILTER_VALIDATE_EMAIL)))
            || (null === $email && $emailVerified)
            || '' === trim($displayName)
            || mb_strlen($displayName, 'UTF-8') > 80
            || 1 !== preg_match('//u', $displayName)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $displayName)
        ) {
            throw new \InvalidArgumentException('Das externe Mitgliedsprofil ist ungültig.');
        }
    }
}
