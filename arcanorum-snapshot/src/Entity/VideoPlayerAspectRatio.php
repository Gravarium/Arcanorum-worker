<?php

declare(strict_types=1);

namespace App\Entity;

enum VideoPlayerAspectRatio: string
{
    case Widescreen = '16:9';
    case Standard = '4:3';
    case Square = '1:1';
    case Portrait = '9:16';

    /** @return array{width: int, height: int} */
    public function iframeDimensions(VideoPlayerMaxWidth $maximumWidth = VideoPlayerMaxWidth::Fluid): array
    {
        $width = $maximumWidth->iframeWidth();

        return match ($this) {
            self::Widescreen => ['width' => $width, 'height' => (int) round($width * 9 / 16)],
            self::Standard => ['width' => $width, 'height' => (int) round($width * 3 / 4)],
            self::Square => ['width' => $width, 'height' => $width],
            self::Portrait => ['width' => (int) round($width * 9 / 16), 'height' => $width],
        };
    }
}
