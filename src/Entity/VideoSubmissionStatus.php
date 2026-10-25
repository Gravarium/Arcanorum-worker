<?php

declare(strict_types=1);

namespace App\Entity;

enum VideoSubmissionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Wartet auf Prüfung',
            self::Approved => 'Freigegeben',
            self::Rejected => 'Abgelehnt',
        };
    }
}
