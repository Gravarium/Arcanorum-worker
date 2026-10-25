<?php

declare(strict_types=1);

namespace App\Entity;

enum PublishingStatus: string
{
    case Draft = 'draft';
    case Published = 'published';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Entwurf',
            self::Published => 'Veröffentlicht',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::Draft => 'is-later',
            self::Published => 'is-verified',
        };
    }
}
