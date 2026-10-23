<?php

declare(strict_types=1);

namespace App\Module\Moderation;

enum ModeratorPermission: string
{
    case ModerateVideos = 'video_moderation';
    case ModerateComments = 'comment_moderation';
}
