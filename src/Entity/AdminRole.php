<?php

declare(strict_types=1);

namespace App\Entity;

use App\Module\Moderation\ModeratorAccessPolicy;
use App\Module\Moderation\ModeratorPermission;

enum AdminRole: string
{
    case Administrator = 'ROLE_ADMIN';
    case Moderator = 'ROLE_MODERATOR';
    case VideoModerator = 'ROLE_MODERATOR_VIDEO';
    case CommentModerator = 'ROLE_MODERATOR_COMMENTS';

    public static function supports(string $role): bool
    {
        return null !== self::tryFrom($role);
    }

    /** @param list<string> $roles */
    public static function hasStaffAccess(array $roles): bool
    {
        $policy = new ModeratorAccessPolicy();

        return $policy->allows($roles, ModeratorPermission::ModerateVideos)
            || $policy->allows($roles, ModeratorPermission::ModerateComments);
    }

    /** @param list<string> $roles */
    public static function canModerateVideos(array $roles): bool
    {
        return new ModeratorAccessPolicy()->allows($roles, ModeratorPermission::ModerateVideos);
    }

    /** @param list<string> $roles */
    public static function canModerateComments(array $roles): bool
    {
        return new ModeratorAccessPolicy()->allows($roles, ModeratorPermission::ModerateComments);
    }
}
