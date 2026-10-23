<?php

declare(strict_types=1);

namespace App\Module\Moderation;

enum ModeratorAssignmentDecision
{
    case Allowed;
    case Unchanged;
    case ActorDenied;
    case CsrfRequired;
    case ActorMfaRequired;
    case TargetMfaRequired;
    case InvalidRequestedRole;
    case InvalidIdentity;
    case SelfChangeForbidden;
    case LastAdministratorProtected;
    case InvalidRosterState;
}
