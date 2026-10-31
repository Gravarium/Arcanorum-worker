<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

final class SocialOAuthCallbackUrl
{
    public static function fromBaseUrl(string $baseUrl, string $provider): ?string
    {
        if (!in_array($provider, ['facebook', 'twitter'], true)) {
            return null;
        }

        $parts = parse_url(trim($baseUrl));
        if (!is_array($parts)
            || 'https' !== strtolower($parts['scheme'] ?? '')
            || !isset($parts['host'])
            || '' === $parts['host']
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)
        ) {
            return null;
        }

        $host = strtolower($parts['host']);
        if (1 !== preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host)) {
            return null;
        }

        $port = isset($parts['port']) && 443 !== $parts['port'] ? ':'.$parts['port'] : '';

        return 'https://'.$host.$port.'/konto/sozial/'.$provider.'/callback';
    }
}
