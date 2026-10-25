<?php

declare(strict_types=1);

namespace App\Platform\Security;

enum ReplayProtectionDecision
{
    case Accepted;
    case ReplayDetected;
    case Unavailable;
}
