<?php

declare(strict_types=1);

namespace App\Platform\Security;

final class RuntimeAttestationBearerToken
{
    public const int MAX_TOTAL_LENGTH = 8192;

    public static function isValid(string $token): bool
    {
        return strlen($token) <= self::MAX_TOTAL_LENGTH
            && 1 === preg_match('/\A[A-Za-z0-9._~+\/-]{16,8192}={0,}\z/D', $token);
    }
}
