<?php

declare(strict_types=1);

namespace App\Entity;

enum VideoThumbnailResolution: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case ExtraLarge = 'extra-large';
    case Original = 'original';

    public function targetWidth(): ?int
    {
        return match ($this) {
            self::Small => 320,
            self::Medium => 640,
            self::Large => 1280,
            self::ExtraLarge => 1920,
            self::Original => null,
        };
    }
}
