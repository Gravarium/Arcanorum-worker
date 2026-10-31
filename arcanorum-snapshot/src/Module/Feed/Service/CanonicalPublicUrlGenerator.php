<?php

declare(strict_types=1);

namespace App\Module\Feed\Service;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class CanonicalPublicUrlGenerator
{
    private ?string $origin;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        string $publicBaseUrl,
    ) {
        $this->origin = self::parseOrigin($publicBaseUrl);
    }

    public function isConfigured(): bool
    {
        return null !== $this->origin;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $route, array $parameters = []): string
    {
        if (null === $this->origin) {
            throw new \LogicException('Die öffentliche Basisadresse ist nicht sicher konfiguriert.');
        }

        $path = $this->urlGenerator->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_PATH);
        if (!str_starts_with($path, '/')
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || 1 === preg_match('/[\x00-\x20\x7f]/', $path)
        ) {
            throw new \LogicException('Die öffentliche Route konnte nicht sicher erzeugt werden.');
        }

        return $this->origin.$path;
    }

    private static function parseOrigin(string $publicBaseUrl): ?string
    {
        if ('' === $publicBaseUrl
            || str_contains($publicBaseUrl, '\\')
            || 1 === preg_match('/[\x00-\x20\x7f]/', $publicBaseUrl)
            || false === filter_var($publicBaseUrl, FILTER_VALIDATE_URL)
        ) {
            return null;
        }

        try {
            $parts = parse_url($publicBaseUrl);
        } catch (\ValueError) {
            return null;
        }
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
        if (1 !== preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        if (null !== $port && $port < 1) {
            return null;
        }

        return 'https://'.$host.(null !== $port && 443 !== $port ? ':'.$port : '');
    }
}
