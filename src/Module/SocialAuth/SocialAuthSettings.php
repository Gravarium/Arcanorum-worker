<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

final readonly class SocialAuthSettings
{
    /**
     * @var array<string, true>
     */
    private array $enabledProviders;

    /** @var array<string, string> */
    private array $callbackUris;

    /** @var array<string, string> */
    private array $authorizationHosts;

    /**
     * @param array<array-key, mixed> $enabledProviders
     * @param array<mixed, mixed>     $callbackUris
     * @param array<mixed, mixed>     $authorizationHosts
     */
    public function __construct(
        array $enabledProviders,
        private bool $registrationEnabled,
        array $callbackUris,
        array $authorizationHosts,
        private int $stateLifetimeSeconds = 300,
        private int $maxProfileResponseBytes = 16_384,
        private int $reauthenticationWindowSeconds = 300,
    ) {
        if ($stateLifetimeSeconds < 60 || $stateLifetimeSeconds > 900) {
            throw new \InvalidArgumentException('Die Anmeldedauer ist ungueltig.');
        }

        if ($maxProfileResponseBytes < 256 || $maxProfileResponseBytes > 65_536) {
            throw new \InvalidArgumentException('Die Antwortgrenze ist ungueltig.');
        }

        if ($reauthenticationWindowSeconds < 30 || $reauthenticationWindowSeconds > 900) {
            throw new \InvalidArgumentException('Das Reauthentifizierungsfenster ist ungueltig.');
        }

        if (!array_is_list($enabledProviders)) {
            throw new \InvalidArgumentException('Die Anbieterliste ist ungueltig.');
        }

        $enabled = [];
        foreach ($enabledProviders as $providerId) {
            if (!is_string($providerId)) {
                throw new \InvalidArgumentException('Ein Anmeldeanbieter ist ungueltig.');
            }

            $provider = Provider::tryFrom($providerId);
            if (null === $provider) {
                throw new \InvalidArgumentException('Ein Anmeldeanbieter ist ungueltig.');
            }

            $enabled[$provider->value] = true;
        }

        $this->enabledProviders = $enabled;
        $providerSettings = $this->validatedProviderSettings($callbackUris, $authorizationHosts);
        $this->callbackUris = $providerSettings['callbackUris'];
        $this->authorizationHosts = $providerSettings['authorizationHosts'];
    }

    public function isProviderEnabled(Provider $provider): bool
    {
        return isset($this->enabledProviders[$provider->value]);
    }

    public function isRegistrationEnabled(): bool
    {
        return $this->registrationEnabled;
    }

    public function callbackUri(Provider $provider): string
    {
        /** @var array<string, string> $callbackUris */
        $callbackUris = $this->callbackUris;

        return $callbackUris[$provider->value];
    }

    public function authorizationHost(Provider $provider): string
    {
        /** @var array<string, string> $authorizationHosts */
        $authorizationHosts = $this->authorizationHosts;

        return $authorizationHosts[$provider->value];
    }

    public function stateLifetimeSeconds(): int
    {
        return $this->stateLifetimeSeconds;
    }

    public function maxProfileResponseBytes(): int
    {
        return $this->maxProfileResponseBytes;
    }

    public function reauthenticationWindowSeconds(): int
    {
        return $this->reauthenticationWindowSeconds;
    }

    /**
     * @param array<mixed, mixed> $callbackUris
     * @param array<mixed, mixed> $authorizationHosts
     *
     * @return array{callbackUris: array<string, string>, authorizationHosts: array<string, string>}
     */
    private function validatedProviderSettings(array $callbackUris, array $authorizationHosts): array
    {
        $expected = array_map(static fn (Provider $provider): string => $provider->value, Provider::cases());
        $callbackKeys = array_keys($callbackUris);
        $hostKeys = array_keys($authorizationHosts);
        sort($expected);
        sort($callbackKeys);
        sort($hostKeys);

        if ($callbackKeys !== $expected || $hostKeys !== $expected) {
            throw new \InvalidArgumentException('Die Anbieterkonfiguration ist unvollstaendig.');
        }

        /** @var array<string, string> $validatedCallbackUris */
        $validatedCallbackUris = [];
        /** @var array<string, string> $validatedAuthorizationHosts */
        $validatedAuthorizationHosts = [];
        foreach (Provider::cases() as $provider) {
            $callbackUri = $callbackUris[$provider->value] ?? null;
            $authorizationHost = $authorizationHosts[$provider->value] ?? null;
            if (!is_string($callbackUri) || !is_string($authorizationHost)) {
                throw new \InvalidArgumentException('Die Anbieterkonfiguration ist ungueltig.');
            }

            $this->assertHttpsCallbackUri($callbackUri);
            $this->assertAuthorizationHost($authorizationHost);
            $validatedCallbackUris[$provider->value] = $callbackUri;
            $validatedAuthorizationHosts[$provider->value] = strtolower($authorizationHost);
        }

        return [
            'callbackUris' => $validatedCallbackUris,
            'authorizationHosts' => $validatedAuthorizationHosts,
        ];
    }

    private function assertHttpsCallbackUri(string $callbackUri): void
    {
        $parts = parse_url($callbackUri);
        if (!is_array($parts)) {
            throw new \InvalidArgumentException('Die Callback-Adresse ist ungueltig.');
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '';
        if (
            !is_string($scheme)
            || 'https' !== strtolower($scheme)
            || !is_string($host)
            || '' === $host
            || false !== filter_var($host, FILTER_VALIDATE_IP)
            || 1 !== preg_match('/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\z/D', $host)
            || 1 === preg_match('/[\x00-\x20\x7F]/', $path)
            || str_contains($path, chr(92))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['port'])
        ) {
            throw new \InvalidArgumentException('Die Callback-Adresse ist ungueltig.');
        }
    }

    private function assertAuthorizationHost(string $host): void
    {
        if (
            '' === $host
            || strlen($host) > 253
            || false !== filter_var($host, FILTER_VALIDATE_IP)
            || 1 !== preg_match('/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\z/D', $host)
        ) {
            throw new \InvalidArgumentException('Der Anbieterhost ist ungueltig.');
        }
    }
}
