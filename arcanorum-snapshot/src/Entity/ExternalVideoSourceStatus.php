<?php

declare(strict_types=1);

namespace App\Entity;

enum ExternalVideoSourceStatus: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case Unsupported = 'unsupported';
}
