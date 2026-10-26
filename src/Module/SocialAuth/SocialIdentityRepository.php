<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

interface SocialIdentityRepository
{
    public function accountIdFor(Provider $provider, string $subject): ?string;

    /** Atomically register an account only when this provider identity is unused. */
    public function registerAccount(ProviderIdentity $identity): ?string;

    /** Atomically attach only when the provider identity is unused; never overwrite. */
    public function linkToAccount(string $accountId, ProviderIdentity $identity): bool;

    /** Atomically remove only when another authentication method remains. */
    public function unlinkIfAnotherMethodRemains(string $accountId, Provider $provider): bool;
}
