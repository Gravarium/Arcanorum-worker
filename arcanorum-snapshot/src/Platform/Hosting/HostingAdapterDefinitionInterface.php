<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

interface HostingAdapterDefinitionInterface
{
    public function identifier(): string;

    public function service(): HostingService;

    /**
     * @return list<HostingCapability>
     */
    public function requiredCapabilities(): array;

    public function priority(): int;

    public function isFallback(): bool;
}
