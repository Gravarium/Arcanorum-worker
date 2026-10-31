<?php

declare(strict_types=1);

namespace App\Module\Moderation;

enum ModeratorRole: string
{
    case Administrator = 'ROLE_ADMIN';
    case Moderator = 'ROLE_MODERATOR';
    case VideoModerator = 'ROLE_MODERATOR_VIDEO';
    case CommentModerator = 'ROLE_MODERATOR_COMMENTS';
    case Member = 'ROLE_USER';

    public function isAssignable(): bool
    {
        return self::Member !== $this;
    }

    public function allows(ModeratorPermission $permission): bool
    {
        return match ($this) {
            self::Administrator, self::Moderator => true,
            self::VideoModerator => ModeratorPermission::ModerateVideos === $permission,
            self::CommentModerator => ModeratorPermission::ModerateComments === $permission,
            self::Member => false,
        };
    }
}
