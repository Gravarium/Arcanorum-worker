<?php

declare(strict_types=1);

namespace App\Entity;

enum MemberFollowResult: string
{
    case Added = 'added';
    case AlreadyFollowing = 'already_following';
    case LimitReached = 'limit_reached';
    case Unavailable = 'unavailable';
}
