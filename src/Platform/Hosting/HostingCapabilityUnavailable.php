<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

final class HostingCapabilityUnavailable extends \RuntimeException
{
    public function __construct(
        public readonly HostingCapability $capability,
        public readonly CapabilityStatus $status,
    ) {
        parent::__construct(sprintf(
            'Die für diese Aktion erforderliche Hostingfähigkeit "%s" ist nicht bestätigt (%s).',
            $capability->label(),
            $status->label(),
        ));
    }
}
