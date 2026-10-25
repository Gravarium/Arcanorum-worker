<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final readonly class RedisCachePoolAdapter implements CachePoolAdapterInterface
{
    public function __construct(
        private string $redisDsn,
    ) {
    }

    public function identifier(): string
    {
        return 'cache.redis_private';
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
        return [
            HostingCapability::RedisServer8,
            HostingCapability::RedisPrivateEndpoint,
        ];
    }

    public function priority(): int
    {
        return 100;
    }

    public function isFallback(): bool
    {
        return false;
    }

    public function createPool(string $namespace): CacheItemPoolInterface
    {
        $client = RedisAdapter::createConnection($this->redisDsn, [
            'lazy' => false,
            'timeout' => 1.0,
            'read_timeout' => 1.0,
        ]);

        return new RedisAdapter($client, $namespace, 0);
    }
}
