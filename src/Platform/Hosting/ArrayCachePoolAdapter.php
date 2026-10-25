<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final readonly class ArrayCachePoolAdapter implements CachePoolAdapterInterface
{
    public function identifier(): string
    {
        return 'cache.array';
    }

    public function service(): HostingService
    {
        return HostingService::Cache;
    }

    /**
     * @return list<HostingCapability>
     */
    public function requiredCapabilities(): array
    {
        return [];
    }

    public function priority(): int
    {
        return 0;
    }

    public function isFallback(): bool
    {
        return true;
    }

    public function createPool(string $namespace): CacheItemPoolInterface
    {
        return new ArrayAdapter(0, true);
    }
}
