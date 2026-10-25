<?php

declare(strict_types=1);

namespace App\Tests\Module\SocialAuth\Support;

use App\Module\SocialAuth\OAuthState;
use App\Module\SocialAuth\OAuthStateStore;
use LogicException;

final class FakeOAuthStateStore implements OAuthStateStore
{
    /** @var array<string, OAuthState> */
    public array $records = [];

    public function store(string $stateDigest, OAuthState $state): void
    {
        if (isset($this->records[$stateDigest])) {
            throw new LogicException('Duplicate state digest.');
        }

        $this->records[$stateDigest] = $state;
    }

    public function consume(string $stateDigest): ?OAuthState
    {
        $state = $this->records[$stateDigest] ?? null;
        unset($this->records[$stateDigest]);

        return $state;
    }

    public function recordCount(): int
    {
        return count($this->records);
    }
}
