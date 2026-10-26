<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

interface OAuthProviderClientRegistry
{
    public function clientFor(Provider $provider): OAuthProviderClient;
}
