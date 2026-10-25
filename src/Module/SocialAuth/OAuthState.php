<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

final readonly class OAuthState
{
    public function __construct(
        public Provider $provider,
        public Flow $flow,
        public string $codeVerifier,
        public string $callbackUri,
        public ?string $accountId,
        public string $sessionBindingDigest,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
