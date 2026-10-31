<?php

declare(strict_types=1);

namespace App\Entity;

enum VideoPlayerEngine: string
{
    case Native = 'native';
    case VideoJs = 'videojs';
}
