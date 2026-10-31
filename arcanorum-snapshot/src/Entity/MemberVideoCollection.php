<?php

declare(strict_types=1);

namespace App\Entity;

enum MemberVideoCollection: string
{
    case WatchLater = 'watch_later';
    case Favorite = 'favorite';

    public function label(): string
    {
        return match ($this) {
            self::WatchLater => 'Später ansehen',
            self::Favorite => 'Favoriten',
        };
    }
}
