<?php

declare(strict_types=1);

namespace App\Entity;

enum VideoReportReason: string
{
    case BrokenMedia = 'broken_media';
    case Inappropriate = 'inappropriate';
    case Copyright = 'copyright';
    case Misleading = 'misleading';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::BrokenMedia => 'Video oder Quelle funktioniert nicht',
            self::Inappropriate => 'Unangemessener Inhalt',
            self::Copyright => 'Urheberrecht oder Rechte',
            self::Misleading => 'Irreführende Angaben',
            self::Other => 'Sonstiger Grund',
        };
    }
}
