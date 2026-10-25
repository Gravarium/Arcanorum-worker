<?php

declare(strict_types=1);

namespace App\Module\Admin\Service;

enum AdminRoleChangeResult
{
    case Updated;
    case Unchanged;
    case SelfChangeForbidden;
    case MfaRequired;
    case ActorMfaRequired;
    case CsrfRequired;
    case LastAdministratorProtected;
    case InvalidRosterState;
    case InvalidRole;
    case TargetNotFound;
    case ActorNoLongerAdministrator;
}
