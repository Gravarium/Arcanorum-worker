<?php

declare(strict_types=1);

namespace App\Service;

final readonly class MemberAccountDeletionResult
{
    public function __construct(
        public bool $deleted,
        public int $pendingPrivateObjects,
    ) {
        if ($pendingPrivateObjects < 0) {
            throw new \InvalidArgumentException('Die Zahl ausstehender privater Objekte ist ungültig.');
        }
    }
}
