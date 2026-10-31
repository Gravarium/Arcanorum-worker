<?php

declare(strict_types=1);

namespace App\Platform\Hosting;

enum CapabilityStatus: string
{
    case Verified = 'verified';
    case Failed = 'failed';
    case NotAvailable = 'not_available';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Verified => 'Bestanden',
            self::Failed => 'Fehlgeschlagen',
            self::NotAvailable => 'Nicht verfügbar',
            self::Unknown => 'Unbekannt',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::Verified => 'is-verified',
            self::Failed => 'is-failed',
            self::NotAvailable => 'is-not-available',
            self::Unknown => 'is-unknown',
        };
    }
}
