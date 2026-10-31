<?php

declare(strict_types=1);

namespace App\Entity;

enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Reported = 'reported';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Wartet auf Freigabe',
            self::Approved => 'Freigegeben',
            self::Reported => 'Gemeldet',
            self::Rejected => 'Abgelehnt',
        };
    }
}
