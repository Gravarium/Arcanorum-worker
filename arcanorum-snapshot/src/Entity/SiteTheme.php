<?php

declare(strict_types=1);

namespace App\Entity;

enum SiteTheme: string
{
    case Arcanorum = 'arcanorum';
    case Editorial = 'editorial';
    case Arcade = 'arcade';

    public function label(): string
    {
        return match ($this) {
            self::Arcanorum => 'Arcanorum',
            self::Editorial => 'Redaktionell',
            self::Arcade => 'Arcade',
        };
    }
}
