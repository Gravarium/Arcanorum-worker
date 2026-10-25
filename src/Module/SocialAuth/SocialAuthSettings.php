<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

use InvalidArgumentException;

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
     * @param list<string> $enabledProviders
     * @param array<string, string> $callbackUris
     * @param array<string, string> $authorizationHosts
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
            throw new InvalidArgumentException('Die Anmeldedauer ist ungueltig.');
        }

        if ($maxProfileResponseBytes < 256 || $maxProfileResponseBytes > 65_536) {
            throw new InvalidArgumentException('Die Antwortgrenze ist ungueltig.');
        }

        if ($reauthenticationWindowSeconds < 30 || $reauthenticationWindowSeconds > 900) {
            throw new InvalidArgumentException('Das Reauthentifizierungsfenster ist ungueltig.');
        }

        if (!array_is_list($enabledProviders)) {
            throw new InvalidArgumentException('Die Anbieterliste ist ungueltig.');
        }

        $enabled = [];
        foreach ($enabledProviders as $providerId) {
            if (!is_string($providerId)) {
                throw new InvalidArgumentException('Ein Anmeldeanbieter ist ungueltig.');
            }

            $provider = Provider::tryFrom($providerId);
            if ($provider === null) {
                throw new InvalidArgumentException('Ein Anmeldeanbieter ist ungueltig.');
            }

            $enabled[$provider->value] = true;
        }

        $this->enabledProviders = $enabled;
        $this->assertProviderSettings($callbackUris, $authorizationHosts);
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
     * @param array<string, string> $callbackUris
     * @param array<string, string> $authorizationHosts
     */
    private function assertProviderSettings(array $callbackUris, array $authorizationHosts): void
    {
        $expected = array_map(static fn (Provider $provider): string => $provider->value, Provider::cases());
        $callbackKeys = array_keys($callbackUris);
        $hostKeys = array_keys($authorizationHosts);
        sort($expected);
        sort($callbackKeys);
        sort($hostKeys);

        if ($callbackKeys !== $expected || $hostKeys !== $expected) {
            throw new InvalidArgumentException('Die Anbieterkonfiguration ist unvollstaendig.');
        }

        foreach (Provider::cases() as $provider) {
            if (!is_string($callbackUris[$provider->value] ?? null) || !is_string($authorizationHosts[$provider->value] ?? null)) {
                throw new InvalidArgumentException('Die Anbieterkonfiguration ist ungueltig.');
            }

            $this->assertHttpsCallbackUri($callbackUris[$provider->value]);
            $this->assertAuthorizationHost($authorizationHosts[$provider->value]);
        }

        $this->callbackUris = $callbackUris;
        $this->authorizationHosts = array_map('strtolower', $authorizationHosts);
    }

    private function assertHttpsCallbackUri(string $callbackUri): void
    {
        $parts = parse_url($callbackUri);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\z/D', $host) !== 1
            || preg_match('/[\x00-\x20\x7F]/', $path) === 1
            || str_contains($path, chr(92))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['port'])
        ) {
            throw new InvalidArgumentException('Die Callback-Adresse ist ungueltig.');
        }
    }

    private function assertAuthorizationHost(string $host): void
    {
        if (
            $host === ''
            || strlen($host) > 253
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A(?=.{1,253}\z)(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\z/D', $host) !== 1
        ) {
            throw new InvalidArgumentException('Der Anbieterhost ist ungueltig.');
        }
    }
}
