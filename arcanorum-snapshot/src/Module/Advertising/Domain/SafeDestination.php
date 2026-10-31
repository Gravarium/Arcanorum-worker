<?php

declare(strict_types=1);

namespace App\Module\Advertising\Domain;

final readonly class SafeDestination
{
    private function __construct(public string $url)
    {
    }

    public static function fromString(string $url): self
    {
        if ('' === $url || strlen($url) > 2048 || 1 === preg_match('/[\x00-\x20\x7f\\\\]/', $url) || false === filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Destination must be a valid HTTPS URL.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Destination must be a valid HTTPS URL without user information.');
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        if (!is_string($scheme)
            || 'https' !== strtolower($scheme)
            || !is_string($host)
            || '' === $host
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('Destination must be a valid HTTPS URL without user information.');
        }

        return new self($url);
    }
}
