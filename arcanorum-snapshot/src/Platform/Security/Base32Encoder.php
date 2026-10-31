<?php

declare(strict_types=1);

namespace App\Platform\Security;

final readonly class Base32Encoder
{
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function encode(string $bytes): string
    {
        if ('' === $bytes) {
            return '';
        }

        $buffer = 0;
        $bits = 0;
        $encoded = '';

        for ($index = 0, $length = strlen($bytes); $index < $length; ++$index) {
            $buffer = ($buffer << 8) | ord($bytes[$index]);
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($buffer >> $bits) & 31];
            }

            $buffer &= (1 << $bits) - 1;
        }

        if ($bits > 0) {
            $encoded .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }
}
