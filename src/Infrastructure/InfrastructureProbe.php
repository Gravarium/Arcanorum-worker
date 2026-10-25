<?php

declare(strict_types=1);

namespace App\Infrastructure;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class InfrastructureProbe
{
    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function checkDatabase(): void
    {
        $version = $this->connection->fetchOne('SHOW server_version_num');
        if ((!is_int($version) && !is_string($version)) || 1 !== preg_match('/^18[0-9]{4}$/', (string) $version)) {
            throw new \RuntimeException('PostgreSQL 18 is required.');
        }

        if (1 !== $this->connection->fetchOne('SELECT 1')) {
            throw new \RuntimeException('The database readiness query failed.');
        }
    }

    public function checkCache(): void
    {
        $key = 'walker_probe_'.bin2hex(random_bytes(16));
        $value = bin2hex(random_bytes(16));

        try {
            $item = $this->cache->getItem($key);
            if ($item->isHit()) {
                throw new \RuntimeException('The cache probe key is not isolated.');
            }

            $item->set($value);
            $item->expiresAfter(30);
            if (!$this->cache->save($item)) {
                throw new \RuntimeException('The cache probe could not be saved.');
            }

            $saved = $this->cache->getItem($key);
            if (!$saved->isHit() || $value !== $saved->get()) {
                throw new \RuntimeException('The cache probe could not be read.');
            }
        } finally {
            if (!$this->cache->deleteItem($key)) {
                throw new \RuntimeException('The cache probe could not be removed.');
            }
        }
    }
}
