<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final class HostingAdapterAmbiguous extends \RuntimeException
{
    public function __construct(public readonly HostingService $service)
    {
        parent::__construct(sprintf(
            'Für den Dienst „%s“ sind mehrere gleichrangige Adapter verfügbar.',
            $service->value,
        ));
    }
}
