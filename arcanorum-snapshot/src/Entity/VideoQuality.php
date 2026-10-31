<?php

declare(strict_types=1);

namespace App\Entity;

enum VideoQuality: string
{
    case P240 = '240p';
    case P360 = '360p';
    case P480 = '480p';
    case P720 = '720p';
    case P1080 = '1080p';
    case P1440 = '1440p';
    case P2160 = '2160p';

    public function height(): int
    {
        return (int) substr($this->value, 0, -1);
    }
}
