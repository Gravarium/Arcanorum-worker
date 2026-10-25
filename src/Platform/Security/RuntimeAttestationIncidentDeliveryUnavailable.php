<?php

declare(strict_types=1);

namespace App\Platform\Security;

final class RuntimeAttestationIncidentDeliveryUnavailable extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Die unabhängige Sicherheitsmeldung konnte nicht übermittelt werden.');
    }
}
