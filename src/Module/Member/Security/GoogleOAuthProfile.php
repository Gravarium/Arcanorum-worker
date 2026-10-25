<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

final readonly class GoogleOAuthProfile
{
    public function __construct(
        public string $subject,
        public string $email,
        public string $displayName,
    ) {
    }
}
