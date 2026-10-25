<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

interface OAuthProviderClient
{
    public function authorizationEndpoint(): string;

    /** @return array<string, string> */
    public function authorizationParameters(): array;

    /**
     * Return a normalized identity document with a stable `subject`, optional `email`,
     * and boolean `email_verified`. Enforce the byte limit while reading each provider
     * response and return no more than that many normalized bytes. Never log tokens or data.
     */
    public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $callbackUri, int $maxResponseBytes): string;
}
