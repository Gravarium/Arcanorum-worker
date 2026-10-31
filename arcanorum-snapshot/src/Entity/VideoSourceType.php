<?php

declare(strict_types=1);

namespace App\Entity;

enum VideoSourceType: string
{
    case Url = 'url';
    case Stream = 'stream';
    case Embed = 'embed';
    case File = 'file';

    public function label(): string
    {
        return match ($this) {
            self::Url => 'Video-URL',
            self::Stream => 'Stream',
            self::Embed => 'Einbettung',
            self::File => 'Datei-Upload',
        };
    }
}
