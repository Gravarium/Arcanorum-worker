<?php

declare(strict_types=1);

namespace App\Entity;

enum VideoPlayerMaxWidth: string
{
    case Fluid = 'fluid';
    case Compact = '640';
    case Standard = '800';
    case Wide = '1024';

    public function iframeWidth(): int
    {
        return match ($this) {
            self::Fluid, self::Compact => 640,
            self::Standard => 800,
            self::Wide => 1024,
        };
    }
}
