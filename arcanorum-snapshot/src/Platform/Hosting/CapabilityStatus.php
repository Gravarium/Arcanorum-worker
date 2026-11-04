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

    public function operatorGuidance(): string
    {
        return match ($this) {
            self::Verified => 'Der technische Nachweis ist aktuell erfolgreich. Eine Zusatzfunktion wird trotzdem nur mit vorhandener Integration und gespeicherter Auswahl verwendet.',
            self::Failed => 'Der Nachweis widerspricht einer Projektvorgabe. Die abhängige Zusatzfunktion bleibt gesperrt.',
            self::NotAvailable => 'Der Prüfdienst oder die benötigte Funktion ist nicht erreichbar. Die abhängige Zusatzfunktion bleibt aus.',
            self::Unknown => 'Es liegt kein ausreichender aktueller Nachweis vor. Behandeln Sie diese Fähigkeit nicht als verfügbar.',
        };
    }
}
