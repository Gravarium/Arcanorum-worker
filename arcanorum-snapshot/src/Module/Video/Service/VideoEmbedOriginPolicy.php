<?php

declare(strict_types=1);

namespace App\Module\Video\Service;

final class VideoEmbedOriginPolicy
{
    private const int MAX_ORIGINS = 20;

    private const int MAX_INPUT_LENGTH = 4000;

    /** @return list<string>|null */
    public function parseInput(string $input): ?array
    {
        if (mb_strlen($input, 'UTF-8') > self::MAX_INPUT_LENGTH
            || false === preg_match('//u', $input)
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $input)
        ) {
            return null;
        }

        $lines = preg_split('/\r\n|\n|\r/', $input);
        if (!is_array($lines) || count($lines) > self::MAX_ORIGINS + 1) {
            return null;
        }

        $origins = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            $origin = self::normalizeOrigin($line);
            if (null === $origin) {
                return null;
            }
            if (!in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
            if (count($origins) > self::MAX_ORIGINS) {
                return null;
            }
        }

        return $origins;
    }

    /** @return list<string>|null */
    public function validateStoredOrigins(mixed $origins): ?array
    {
        if (!is_array($origins) || !array_is_list($origins) || count($origins) > self::MAX_ORIGINS) {
            return null;
        }

        $validated = [];
        foreach ($origins as $origin) {
            if (!is_string($origin) || $origin !== self::normalizeOrigin($origin)) {
                return null;
            }
            if (!in_array($origin, $validated, true)) {
                $validated[] = $origin;
            }
        }

        return $validated;
    }

    private static function normalizeOrigin(string $origin): ?string
    {
        if ('' === $origin
            || strlen($origin) > 300
            || str_contains($origin, '\\')
            || 1 === preg_match('/[\x00-\x20\x7f]/', $origin)
        ) {
            return null;
        }

        try {
            $parts = parse_url($origin);
        } catch (\ValueError) {
            return null;
        }
        if (!is_array($parts)
            || 'https' !== strtolower($parts['scheme'] ?? '')
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['path'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !is_string($parts['host'] ?? null)
        ) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (str_contains($host, ':')
            || false !== filter_var($host, FILTER_VALIDATE_IP)
            || 1 !== preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host)
            || false === filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        ) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if (null !== $port && $port < 1) {
            return null;
        }

        return 'https://'.$host.(null !== $port && 443 !== $port ? ':'.$port : '');
    }
}
