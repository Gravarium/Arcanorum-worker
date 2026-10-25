<?php

declare(strict_types=1);

namespace App\Module\Settings\Service;

use App\Entity\FeatureModule;
use App\Platform\Hosting\HostingCapability;

final readonly class BuiltInModuleCatalog
{
    /** @return list<BuiltInModule> */
    public function all(): array
    {
        $database = [HostingCapability::PostgreSqlServer18];

        return [
            new BuiltInModule('admin', 'Verwaltung', 'Anmeldung, Rechte und Verwaltungsoberfläche bleiben immer verfügbar.', 'Admin', false, null, []),
            new BuiltInModule('backups', 'Datenbanksicherungen', 'Verschlüsselte Datenbanksicherungen und deren geschützte Verwaltung.', 'Backup', false, null, []),
            new BuiltInModule('categories', 'Kategorien', 'Gemeinsame Kategorisierung für veröffentlichte Inhalte.', 'Category', false, null, []),
            new BuiltInModule('comments', 'Kommentare', 'Besucherkommentare und deren Moderation.', 'Comment', true, FeatureModule::Comments, $database),
            new BuiltInModule('contact', 'Kontakt', 'Kontaktformular und Verwaltungs-Postfach.', 'Contact', true, FeatureModule::Contact, $database),
            new BuiltInModule('feeds', 'Feeds und Robots', 'Robots-Regeln, RSS-Feeds und Sitemaps.', 'Feed', false, null, []),
            new BuiltInModule('identity', 'Mitglieder und Anmeldung', 'Mitgliederkonten, Anmeldung und Sitzungsverwaltung bleiben immer verfügbar.', 'Member', false, null, []),
            new BuiltInModule('news', 'News', 'Redaktionelle News und deren öffentliche Ausgabe.', 'News', true, FeatureModule::News, $database),
            new BuiltInModule('pages', 'Statische Seiten', 'Veröffentlichte Inhaltsseiten und deren Verwaltung.', 'Page', true, FeatureModule::Pages, $database),
            new BuiltInModule('platform', 'Öffentliche Plattform', 'Startseite und zentrale Plattformrouten bleiben immer verfügbar.', 'Platform', false, null, []),
            new BuiltInModule('search', 'Suche', 'Öffentliche Inhaltssuche und Suchverwaltung.', 'Search', true, FeatureModule::Search, $database),
            new BuiltInModule('series', 'Serien', 'Serien, Staffeln, Folgen, Genres und zugehörige Inhalte.', 'Series', true, FeatureModule::Series, $database),
            new BuiltInModule('settings', 'Einstellungen', 'Website- und Datenschutzkonfiguration bleiben immer verfügbar.', 'Settings', false, null, []),
            new BuiltInModule('tags', 'Schlagwörter', 'Gemeinsame Schlagwortverwaltung für Inhalte.', 'Tag', false, null, []),
            new BuiltInModule('videos', 'Videokatalog', 'Der zentrale Videokatalog und die Wiedergabe bleiben immer verfügbar.', 'Video', false, null, []),
        ];
    }

    public function find(string $id): ?BuiltInModule
    {
        foreach ($this->all() as $module) {
            if ($module->id === $id) {
                return $module;
            }
        }

        return null;
    }

    public function forController(string $controllerClass): ?BuiltInModule
    {
        $prefix = 'App\\Module\\';
        if (!str_starts_with($controllerClass, $prefix)) {
            return null;
        }

        foreach ($this->all() as $module) {
            if (str_starts_with($controllerClass, $prefix.$module->namespace.'\\')) {
                return $module;
            }
        }

        return null;
    }

    public function isModuleNamespace(string $controllerClass): bool
    {
        return str_starts_with($controllerClass, 'App\\Module\\');
    }
}
