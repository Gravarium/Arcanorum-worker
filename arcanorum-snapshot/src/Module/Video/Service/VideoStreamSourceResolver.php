<?php

declare(strict_types=1);

namespace App\Module\Video\Service;

use App\Entity\Video;

final readonly class VideoStreamSourceResolver
{
    /** @var array<string, true> */
    private array $allowedHosts;

    /**
     * @param array<array-key, mixed> $allowedHosts exact HTTPS hostnames approved for public media playback
     */
    public function __construct(array $allowedHosts)
    {
        if (!array_is_list($allowedHosts)) {
            throw new \InvalidArgumentException('Die freigegebenen Streamhosts müssen eine Liste sein.');
        }

        $hosts = [];
        foreach ($allowedHosts as $host) {
            if (!is_string($host)) {
                throw new \InvalidArgumentException('Ein freigegebener Streamhost ist ungültig.');
            }

            $normalizedHost = self::normalizePublicHostname($host);
            if (null === $normalizedHost || isset($hosts[$normalizedHost])) {
                throw new \InvalidArgumentException('Ein freigegebener Streamhost ist ungültig oder doppelt.');
            }

            $hosts[$normalizedHost] = true;
        }

        $this->allowedHosts = $hosts;
    }

    public function isConfigured(): bool
    {
        return [] !== $this->allowedHosts;
    }

    /** @return list<string> */
    public function getAllowedOrigins(): array
    {
        return array_map(static fn (string $host): string => 'https://'.$host, array_keys($this->allowedHosts));
    }

    public function resolve(string $url): ?VideoStreamSource
    {
        if (!Video::isSafeHttpsSource($url)) {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || isset($parts['fragment'])
            || !is_string($parts['host'] ?? null)
        ) {
            return null;
        }

        $host = self::normalizePublicHostname($parts['host']);
        if (null === $host || !isset($this->allowedHosts[$host])) {
            return null;
        }

        $extension = strtolower(pathinfo($parts['path'] ?? '', PATHINFO_EXTENSION));
        $mimeType = match ($extension) {
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'ogv', 'ogg' => 'video/ogg',
            'mp3' => 'audio/mpeg',
            'm3u8' => VideoStreamSource::HLS_MIME_TYPE,
            default => null,
        };
        if (null === $mimeType) {
            return null;
        }

        return new VideoStreamSource($url, $mimeType, 'https://'.$host);
    }

    private static function normalizePublicHostname(string $host): ?string
    {
        $host = strtolower($host);
        if (strlen($host) > 253
            || !str_contains($host, '.')
            || false !== filter_var($host, FILTER_VALIDATE_IP)
            || false === filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
        ) {
            return null;
        }

        foreach (explode('.', $host) as $label) {
            if (strlen($label) > 63
                || 1 !== preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label)
            ) {
                return null;
            }
        }

        $labels = explode('.', $host);
        $topLevelLabel = array_last($labels);
        if (strlen($topLevelLabel) < 2 || ctype_digit($topLevelLabel)) {
            return null;
        }

        foreach (['localhost', 'local', 'localdomain', 'internal', 'test', 'invalid', 'example', 'onion'] as $reservedSuffix) {
            if ($host === $reservedSuffix || str_ends_with($host, '.'.$reservedSuffix)) {
                return null;
            }
        }
        if ('home.arpa' === $host || str_ends_with($host, '.home.arpa')) {
            return null;
        }

        return $host;
    }
}
