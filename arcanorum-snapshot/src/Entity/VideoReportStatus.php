<?php

declare(strict_types=1);

namespace App\Entity;

enum VideoReportStatus: string
{
    case Pending = 'pending';
    case Dismissed = 'dismissed';
    case VideoHidden = 'video_hidden';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Offen',
            self::Dismissed => 'Erledigt',
            self::VideoHidden => 'Video ausgeblendet',
        };
    }
}
