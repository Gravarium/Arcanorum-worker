<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ProxyAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final class HostingCachePoolProvider extends ProxyAdapter
{
    private readonly SwitchableCachePool $switchablePool;

    private string $selectedAdapterIdentifier;

    private CacheItemPoolInterface $selectedPool;

    public function __construct(
        private readonly string $namespace,
        private readonly HostingAdapterSelector $selector,
        private readonly HostingProfileDetector $profileDetector,
        private readonly HostingSecurityFeatureSelectionStoreInterface $selectionStore,
        private readonly CachePoolAdapterInterface $safeFallback,
    ) {
        if (HostingService::Cache !== $safeFallback->service()) {
            throw new \InvalidArgumentException('Der Cache-Fallback muss dem Cache-Dienst zugeordnet sein.');
        }

        if (!$safeFallback->isFallback()) {
            throw new \InvalidArgumentException('Der sichere Cache-Fallback muss als Fallback markiert sein.');
        }

        if ([] !== $safeFallback->requiredCapabilities()) {
            throw new \InvalidArgumentException('Der sichere Cache-Fallback darf keine Hostingfähigkeit voraussetzen.');
        }

        if ([] !== $safeFallback->requiredFeatures()) {
            throw new \InvalidArgumentException('Der sichere Cache-Fallback darf keine optionale Schutzfunktion voraussetzen.');
        }

        $selectedAdapter = $this->selectAdapter();
        $this->selectedAdapterIdentifier = $selectedAdapter->identifier();
        $this->selectedPool = $this->createPool($selectedAdapter);
        $this->switchablePool = new SwitchableCachePool($this->selectedPool);
        parent::__construct($this->switchablePool);
    }

    public function pool(): CacheItemPoolInterface
    {
        return $this->selectedPool;
    }

    public function usesRedisPool(): bool
    {
        return $this->selectedPool instanceof RedisAdapter;
    }

    public function reset(): void
    {
        $selectedAdapter = $this->selectAdapter();
        if ($this->selectedAdapterIdentifier !== $selectedAdapter->identifier()) {
            $this->selectedAdapterIdentifier = $selectedAdapter->identifier();
            $this->selectedPool = $this->createPool($selectedAdapter);
            $this->switchablePool->replacePool($this->selectedPool);
        }

        parent::reset();
    }

    private function selectAdapter(): CachePoolAdapterInterface
    {
        try {
            $selectedFeatures = $this->selectionStore->selectedFeatures();
        } catch (\Throwable) {
            return $this->safeFallback;
        }

        $selected = $this->selector->select(
            HostingService::Cache,
            $this->profileDetector->detect(),
            $selectedFeatures,
        );
        if (!$selected instanceof CachePoolAdapterInterface) {
            throw new HostingAdapterUnavailable(HostingService::Cache);
        }

        return $selected;
    }

    private function createPool(CachePoolAdapterInterface $adapter): CacheItemPoolInterface
    {
        try {
            return $adapter->createPool($this->namespace);
        } catch (\Throwable) {
            if ($adapter->isFallback()) {
                throw new HostingAdapterUnavailable(HostingService::Cache);
            }

            return $this->createFallbackPool();
        }
    }

    private function createFallbackPool(): CacheItemPoolInterface
    {
        try {
            return $this->safeFallback->createPool($this->namespace);
        } catch (\Throwable) {
            throw new HostingAdapterUnavailable(HostingService::Cache);
        }
    }
}
