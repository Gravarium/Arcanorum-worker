<?php

declare(strict_types=1);

namespace App\Platform\Security;

final readonly class AdminMfaProvisioningResult
{
    /** @param list<string> $recoveryCodes */
    public function __construct(
        public string $secretBase32,
        public string $otpauthUri,
        public array $recoveryCodes,
    ) {
    }
}
