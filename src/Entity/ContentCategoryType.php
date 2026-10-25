<?php

declare(strict_types=1);

namespace App\Entity;

enum ContentCategoryType: string
{
    case News = 'news';
    case Video = 'video';

    public function getLabel(): string
    {
        return match ($this) {
            self::News => 'News',
            self::Video => 'Video',
        };
    }
}
