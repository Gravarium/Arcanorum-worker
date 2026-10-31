<?php

declare(strict_types=1);

namespace App\Platform\Security;

final class RuntimeAttestationChallenge
{
    public const int BYTE_LENGTH = 32;

    private const int ENCODED_LENGTH = 43;

    public static function generate(): string
    {
        return self::encode(random_bytes(self::BYTE_LENGTH));
    }

    public static function isValid(string $challenge): bool
    {
        if (self::ENCODED_LENGTH !== strlen($challenge)
            || 1 !== preg_match('/\A[A-Za-z0-9_-]+\z/D', $challenge)
        ) {
            return false;
        }

        $decoded = base64_decode(strtr($challenge, '-_', '+/').'=', true);

        return false !== $decoded
            && self::BYTE_LENGTH === strlen($decoded)
            && self::encode($decoded) === $challenge;
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
