<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

interface OAuthStateStore
{
    /** Store by SHA-256 state digest; reject a digest that already exists. */
    public function store(string $stateDigest, OAuthState $state): void;

    /** Atomically return and remove the state record, or return null when absent. */
    public function consume(string $stateDigest): ?OAuthState;
}
