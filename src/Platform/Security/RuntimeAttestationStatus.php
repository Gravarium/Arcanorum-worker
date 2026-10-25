<?php

declare(strict_types=1);

namespace App\Platform\Security;

enum RuntimeAttestationStatus: string
{
    case Verified = 'verified';
    case Failed = 'failed';
    case Unknown = 'unknown';
    case NotAvailable = 'not_available';
}
