<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ProxyAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final class HostingCachePoolProvider extends ProxyAdapter
{
    private readonly CacheItemPoolInterface $selectedPool;

    public function __construct(
        string $namespace,
        HostingAdapterSelector $selector,
        HostingProfileDetector $profileDetector,
        CachePoolAdapterInterface $safeFallback,
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

        $selected = $selector->select(HostingService::Cache, $profileDetector->detect());
        if (!$selected instanceof CachePoolAdapterInterface) {
            throw new HostingAdapterUnavailable(HostingService::Cache);
        }

        try {
            $selectedPool = $selected->createPool($namespace);
        } catch (\Throwable) {
            if ($selected->isFallback()) {
                throw new HostingAdapterUnavailable(HostingService::Cache);
            }

            try {
                $selectedPool = $safeFallback->createPool($namespace);
            } catch (\Throwable) {
                throw new HostingAdapterUnavailable(HostingService::Cache);
            }
        }

        parent::__construct($selectedPool);
        $this->selectedPool = $selectedPool;
    }

    public function pool(): CacheItemPoolInterface
    {
        return $this->selectedPool;
    }

    public function usesRedisPool(): bool
    {
        return $this->selectedPool instanceof RedisAdapter;
    }
}
