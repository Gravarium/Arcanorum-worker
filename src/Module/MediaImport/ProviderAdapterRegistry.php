<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class ProviderAdapterRegistry
{
    /** @var array<string, ProviderAdapter> */
    private array $adapters;

    /**
     * @param list<ProviderAdapter> $adapters
     */
    public function __construct(array $adapters)
    {
        $registered = [];
        foreach ($adapters as $adapter) {
            $key = $adapter->provider()->value;
            if (isset($registered[$key])) {
                throw new \InvalidArgumentException('A provider adapter is registered more than once.');
            }
            $registered[$key] = $adapter;
        }

        $this->adapters = $registered;
    }

    public function forProvider(MediaProvider $provider): ProviderAdapter
    {
        return $this->adapters[$provider->value] ?? throw new ProviderException(ProviderError::Unsupported);
    }
}
