<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

use Psr\Cache\CacheItemPoolInterface;

interface CachePoolAdapterInterface extends HostingAdapterDefinitionInterface
{
    public function createPool(string $namespace): CacheItemPoolInterface;
}
