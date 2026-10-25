<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final class HostingAdapterUnavailable extends \RuntimeException
{
    public function __construct(public readonly HostingService $service)
    {
        parent::__construct(sprintf(
            'Für den Dienst „%s“ ist kein sicher verfügbarer Adapter registriert.',
            $service->value,
        ));
    }
}
