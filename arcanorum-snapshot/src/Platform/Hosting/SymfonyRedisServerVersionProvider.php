<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Symfony\Component\Cache\Adapter\RedisAdapter;

final readonly class SymfonyRedisServerVersionProvider implements RedisServerVersionProviderInterface
{
    public function serverVersion(string $dsn): ?string
    {
        try {
            $client = RedisAdapter::createConnection($dsn, [
                'lazy' => false,
                'timeout' => 1.0,
                'read_timeout' => 1.0,
            ]);
            if (!is_callable([$client, 'info'])) {
                return null;
            }

            $information = call_user_func([$client, 'info'], 'server');
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($information)) {
            return null;
        }

        $version = $information['redis_version'] ?? null;

        return is_string($version) && '' !== trim($version) ? trim($version) : null;
    }
}
