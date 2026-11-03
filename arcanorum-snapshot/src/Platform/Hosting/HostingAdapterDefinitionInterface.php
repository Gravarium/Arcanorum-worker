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

    /**
     * @return list<HostingSecurityFeature>
     */
    public function requiredFeatures(): array;

    public function priority(): int;

    public function isFallback(): bool;
}
