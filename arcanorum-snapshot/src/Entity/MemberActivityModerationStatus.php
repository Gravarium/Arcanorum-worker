<?php

declare(strict_types=1);

namespace App\Entity;

enum MemberActivityModerationStatus: string
{
    case Visible = 'visible';
    case Hidden = 'hidden';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Visible => 'Im Feed',
            self::Hidden => 'Ausgeblendet',
            self::Removed => 'Aus dem Feed entfernt',
        };
    }
}
