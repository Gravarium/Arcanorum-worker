<?php

declare(strict_types=1);

namespace App\Module\SocialAuth;

final readonly class MapOAuthProviderClientRegistry implements OAuthProviderClientRegistry
{
    /** @var array<string, OAuthProviderClient> */
    private array $clients;

    /**
     * @param array<mixed, mixed> $clients
     */
    public function __construct(array $clients)
    {
        $expected = array_map(static fn (Provider $provider): string => $provider->value, Provider::cases());
        $validated = [];
        foreach ($clients as $providerId => $client) {
            if (!is_string($providerId) || null === Provider::tryFrom($providerId) || !$client instanceof OAuthProviderClient) {
                throw new \InvalidArgumentException('Die Anbieterregistrierung ist ungueltig.');
            }

            $validated[$providerId] = $client;
        }

        $configured = array_keys($validated);
        sort($expected);
        sort($configured);

        if ($configured !== $expected) {
            throw new \InvalidArgumentException('Die Anbieterregistrierung ist unvollstaendig.');
        }

        $this->clients = $validated;
    }

    public function clientFor(Provider $provider): OAuthProviderClient
    {
        return $this->clients[$provider->value];
    }
}
