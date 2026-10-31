<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

interface SocialOAuthClientInterface
{
    public function provider(): string;

    public function isConfigured(): bool;

    public function authorizationUrl(string $state, string $codeChallenge): string;

    public function fetchProfile(string $code, string $codeVerifier): SocialOAuthProfile;
}
