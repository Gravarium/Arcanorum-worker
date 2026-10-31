<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

interface RedisServerVersionProviderInterface
{
    public function serverVersion(string $dsn): ?string;
}
