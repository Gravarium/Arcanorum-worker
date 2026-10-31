<?php

declare(strict_types=1);

namespace App\Platform\Security;

final class RuntimeAttestationEndpoint
{
    private const string HOST_PATTERN = '/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/D';

    /**
     * @param array<array-key, mixed> $allowedHosts
     */
    public static function isValid(string $endpoint, array $allowedHosts = []): bool
    {
        if (false === filter_var($endpoint, FILTER_VALIDATE_URL)
            || str_contains($endpoint, '\\')
            || 1 === preg_match('/[\x00-\x20\x7F]/', $endpoint)
        ) {
            return false;
        }

        $parts = parse_url($endpoint);
        $normalizedHosts = self::normalizeAllowedHosts($allowedHosts);
        if (!is_array($parts) || null === $normalizedHosts) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        return 'https' === strtolower($parts['scheme'] ?? '')
            && isset($parts['host'])
            && isset($normalizedHosts[$host])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && (!isset($parts['port']) || $parts['port'] >= 1);
    }

    /**
     * @param array<array-key, mixed> $allowedHosts
     *
     * @return array<string, true>|null
     */
    private static function normalizeAllowedHosts(array $allowedHosts): ?array
    {
        if ([] === $allowedHosts || !array_is_list($allowedHosts) || count($allowedHosts) > 64) {
            return null;
        }

        $normalized = [];
        foreach ($allowedHosts as $host) {
            if (!is_string($host) || '' === $host || trim($host) !== $host) {
                return null;
            }

            $host = strtolower($host);
            if (false !== filter_var($host, FILTER_VALIDATE_IP)
                || 1 === preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){1,3}\z/iD', $host)
                || 1 !== preg_match(self::HOST_PATTERN, $host)
                || isset($normalized[$host])
            ) {
                return null;
            }

            $normalized[$host] = true;
        }

        return $normalized;
    }
}
