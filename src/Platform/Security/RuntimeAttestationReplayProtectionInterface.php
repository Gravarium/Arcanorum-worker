<?php

declare(strict_types=1);

namespace App\Platform\Security;

interface RuntimeAttestationReplayProtectionInterface
{
    /**
     * Atomically accepts a target-bound, unused challenge and a sequence higher
     * than the last accepted sequence for that target.
     *
     * Implementations must keep challenge and sequence state outside the
     * affected host. A local store is not an acceptable implementation.
     */
    public function consume(
        string $targetId,
        string $nonce,
        int $sequence,
        \DateTimeImmutable $expiresAt,
    ): ReplayProtectionDecision;
}
