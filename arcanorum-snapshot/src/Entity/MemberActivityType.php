<?php

declare(strict_types=1);

namespace App\Entity;

enum MemberActivityType: string
{
    case VideoSubmission = 'video_submission';
    case Playlist = 'playlist';
    case VideoComment = 'video_comment';
    case NewsComment = 'news_comment';

    public function label(): string
    {
        return match ($this) {
            self::VideoSubmission => 'Videoeinreichung',
            self::Playlist => 'Öffentliche Wiedergabeliste',
            self::VideoComment => 'Videokommentar',
            self::NewsComment => 'News-Kommentar',
        };
    }
}
