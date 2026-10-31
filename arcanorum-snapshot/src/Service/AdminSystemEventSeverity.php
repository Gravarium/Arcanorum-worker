<?php

declare(strict_types=1);

namespace App\Service;

enum AdminSystemEventSeverity: string
{
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Warnung',
            self::Critical => 'Kritisch',
        };
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::Warning => 'is-warning',
            self::Critical => 'is-critical',
        };
    }
}
