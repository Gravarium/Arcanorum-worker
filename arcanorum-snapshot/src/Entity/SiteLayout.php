<?php

declare(strict_types=1);

namespace App\Entity;

enum SiteLayout: string
{
    case Grid = 'grid';
    case Magazine = 'magazine';
    case Compact = 'compact';

    public function label(): string
    {
        return match ($this) {
            self::Grid => 'Kartenraster',
            self::Magazine => 'Magazin',
            self::Compact => 'Kompakt',
        };
    }
}
