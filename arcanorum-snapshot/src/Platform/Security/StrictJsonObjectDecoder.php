<?php

declare(strict_types=1);

namespace App\Platform\Security;

final class StrictJsonObjectDecoder
{
    private const int MAX_SCAN_DEPTH = 64;

    /**
     * @return array<array-key, mixed>|null
     */
    public static function decode(string $json, int $maxDepth = 16): ?array
    {
        if ($maxDepth < 1 || $maxDepth > self::MAX_SCAN_DEPTH) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, $maxDepth, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        $offset = 0;
        if (!self::scanValue($json, $offset, 0)) {
            return null;
        }

        self::skipWhitespace($json, $offset);

        return strlen($json) === $offset ? $decoded : null;
    }

    private static function scanValue(string $json, int &$offset, int $depth): bool
    {
        self::skipWhitespace($json, $offset);
        if ($depth > self::MAX_SCAN_DEPTH || $offset >= strlen($json)) {
            return false;
        }

        return match ($json[$offset]) {
            '{' => self::scanObject($json, $offset, $depth + 1),
            '[' => self::scanArray($json, $offset, $depth + 1),
            '"' => null !== self::readString($json, $offset),
            default => self::scanPrimitive($json, $offset),
        };
    }

    private static function scanObject(string $json, int &$offset, int $depth): bool
    {
        ++$offset;
        self::skipWhitespace($json, $offset);
        if ('}' === ($json[$offset] ?? null)) {
            ++$offset;

            return true;
        }

        /** @var array<array-key, true> $keys */
        $keys = [];
        while ($offset < strlen($json)) {
            $key = self::readString($json, $offset);
            if (null === $key || array_key_exists($key, $keys)) {
                return false;
            }

            $keys[$key] = true;
            self::skipWhitespace($json, $offset);
            if (':' !== ($json[$offset] ?? null)) {
                return false;
            }

            ++$offset;
            if (!self::scanValue($json, $offset, $depth)) {
                return false;
            }

            self::skipWhitespace($json, $offset);
            if (',' === ($json[$offset] ?? null)) {
                ++$offset;
                self::skipWhitespace($json, $offset);

                continue;
            }

            if ('}' === ($json[$offset] ?? null)) {
                ++$offset;

                return true;
            }

            return false;
        }

        return false;
    }

    private static function scanArray(string $json, int &$offset, int $depth): bool
    {
        ++$offset;
        self::skipWhitespace($json, $offset);
        if (']' === ($json[$offset] ?? null)) {
            ++$offset;

            return true;
        }

        while ($offset < strlen($json)) {
            if (!self::scanValue($json, $offset, $depth)) {
                return false;
            }

            self::skipWhitespace($json, $offset);
            if (',' === ($json[$offset] ?? null)) {
                ++$offset;
                self::skipWhitespace($json, $offset);

                continue;
            }

            if (']' === ($json[$offset] ?? null)) {
                ++$offset;

                return true;
            }

            return false;
        }

        return false;
    }

    private static function readString(string $json, int &$offset): ?string
    {
        if ('"' !== ($json[$offset] ?? null)) {
            return null;
        }

        $start = $offset;
        ++$offset;
        $length = strlen($json);
        while ($offset < $length) {
            if ('"' === $json[$offset]) {
                ++$offset;
                try {
                    $decoded = json_decode(substr($json, $start, $offset - $start), true, 2, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    return null;
                }

                return is_string($decoded) ? $decoded : null;
            }

            if ('\\' === $json[$offset]) {
                $offset += 2;

                continue;
            }

            ++$offset;
        }

        return null;
    }

    private static function scanPrimitive(string $json, int &$offset): bool
    {
        $start = $offset;
        $length = strlen($json);
        while ($offset < $length && !str_contains(",]} \t\r\n", $json[$offset])) {
            ++$offset;
        }

        return $offset > $start;
    }

    private static function skipWhitespace(string $json, int &$offset): void
    {
        $length = strlen($json);
        while ($offset < $length && str_contains(" \t\r\n", $json[$offset])) {
            ++$offset;
        }
    }
}
