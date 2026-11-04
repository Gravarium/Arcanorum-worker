<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ProxyAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps Symfony's cache proxy stable while allowing its backing adapter to be
 * replaced at a worker reset boundary.
 */
final class SwitchableCachePool implements AdapterInterface, ResetInterface
{
    private AdapterInterface $pool;

    public function __construct(CacheItemPoolInterface $pool)
    {
        $this->pool = self::asAdapter($pool);
    }

    public function replacePool(CacheItemPoolInterface $pool): void
    {
        $this->pool = self::asAdapter($pool);
    }

    public function getItem(mixed $key): CacheItem
    {
        return $this->pool->getItem($key);
    }

    /**
     * @return iterable<string, CacheItem>
     */
    public function getItems(array $keys = []): iterable
    {
        return $this->pool->getItems($keys);
    }

    public function hasItem(mixed $key): bool
    {
        return $this->pool->hasItem($key);
    }

    public function clear(string $prefix = ''): bool
    {
        return $this->pool->clear($prefix);
    }

    public function deleteItem(mixed $key): bool
    {
        return $this->pool->deleteItem($key);
    }

    public function deleteItems(array $keys): bool
    {
        return $this->pool->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->pool->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->pool->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->pool->commit();
    }

    public function reset(): void
    {
        if ($this->pool instanceof ResetInterface) {
            $this->pool->reset();
        }
    }

    private static function asAdapter(CacheItemPoolInterface $pool): AdapterInterface
    {
        return $pool instanceof AdapterInterface ? $pool : new ProxyAdapter($pool);
    }
}
