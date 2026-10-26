# Anzeigenmodul für den CMS-Arbeitsbereich

Das Modul verwaltet Anzeigen und Platzierungen, liefert drei streng begrenzte Werbeformen aus und erfasst Tageswerte für Auslieferungen und Klicks. Es ergänzt eigenständige Komponenten unter `App\Module\Advertising` und setzt weder eine vorhandene Benutzerverwaltung noch ein fremdes Werbenetz voraus.

## Funktionen

- Platzierungen und Anzeigen anlegen, bearbeiten und archivieren; Platzierungen und Anzeigen lassen sich aktivieren oder pausieren.
- Eine pausierte Platzierung unterdrückt ihre Auslieferungen und Klicks, ohne die Zustände ihrer Anzeigen zu ändern. Nach der Reaktivierung werden weiterhin aktive Anzeigen wieder ausgeliefert.
- Anzeigen als Entwurf, aktiv, pausiert oder inaktiv führen. Archivierte Anzeigen sind endgültig.
- Aktivieren nur bei vorhandener, nicht archivierter Platzierung mit passendem Anzeigenformat.
- HTML-Banner durch eine Element- und Attribut-Allowlist bereinigen. Skripte, Stile, Formulare, SVG, eingebettete Medien, Ereignisattribute und Links im Inhalt werden entfernt. Bilder müssen lokale Pfade unter `/media/` verwenden.
- Statische Player-Anzeigen und Videos nur mit lokalen Assetpfaden ausgeben. Videos verwenden das native HTML-Element mit `preload="none"`; eingebettete Anbieter, fremde Skripte und fremde Medienquellen sind nicht zugelassen.
- Nur absolute HTTPS-Ziele ohne Zugangsdaten zulassen. Klickpfade enthalten eine signierte, kurzlebige Kennung und leiten ausschließlich zum im Anzeigen-Datensatz gespeicherten Ziel weiter.
- Pro Tag und Anzeige nur aggregierte Auslieferungs- und Klickzähler speichern. Es werden keine IP-Adresse, User-Agent-, Cookie- oder Konto-Kennung gespeichert. Ein zufälliger Einmal-Klickdigest dient nur der Duplikatvermeidung und läuft nach einem Tag ab; ein aggregiertes Limit von 600 Klicks je Anzeige und Minute begrenzt Spitzen. Kurzlebige Deduplizierungs- und Minutenzeilen sind regelmäßig zu bereinigen.

## Aktivierung im CMS-Arbeitsbereich

Die Admin-Routen tragen Symfony-Routenattribute unter `AdvertisingAdminController`; die Auslieferungs- und Klickrouten liegen in `AdvertisingDeliveryController`. Der Host lädt diese Controller beim Integrieren des Moduls über seine Routing-Konfiguration.

`AdvertisingAdminGuard` ist die Integrationsschnittstelle für die vorhandene Berechtigungsprüfung und CSRF-Prüfung. Der mitgelieferte `DenyAllAdvertisingAdminGuard` verweigert alle Admin-Zugriffe. Der Host muss diese Implementierung durch eine an die vorhandene Admin-Sitzung gebundene Implementierung ersetzen. Mutierende API-Aufrufe senden das CSRF-Token im Header `X-CSRF-Token`; GET-Zugriffe auf Verwaltung und Statistik prüfen ebenfalls die Admin-Berechtigung.

Der Klicksignierer verwendet `APP_SECRET`. Klickziele werden nie aus einem Anfrageparameter gelesen. Ein ungültiger, abgelaufener oder wiederholter Klicktoken erhöht den Zähler nicht. Die Weiterleitung ist unabhängig davon an das validierte HTTPS-Ziel des aktiven Anzeigen-Datensatzes gebunden.

## Persistenzvertrag

`DoctrineAdvertisingStore` und `DoctrineStatisticsStore` verwenden Doctrine DBAL und benötigen folgende Tabellen. Die bestehende Aufgabe erlaubt keine gemeinsamen Konfigurations- oder Migrationsdateien; der Host übernimmt dieses Schema daher später in seine übliche Doctrine-Migration. Die Adapter legen keine Tabellen zur Laufzeit an.

```sql
CREATE TABLE cms_advertising_placement (
    id varchar(64) PRIMARY KEY,
    label varchar(120) NOT NULL,
    formats jsonb NOT NULL CHECK (jsonb_typeof(formats) = 'array'),
    archived boolean NOT NULL DEFAULT false,
    paused boolean NOT NULL DEFAULT false
);

CREATE TABLE cms_advertising_ad (
    id char(32) PRIMARY KEY,
    label varchar(120) NOT NULL,
    placement_id varchar(64) NOT NULL REFERENCES cms_advertising_placement (id),
    format varchar(32) NOT NULL,
    creative jsonb NOT NULL CHECK (jsonb_typeof(creative) = 'object'),
    destination_url text NOT NULL,
    status varchar(16) NOT NULL CHECK (status IN ('draft', 'active', 'paused', 'inactive', 'archived')),
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL
);

CREATE TABLE cms_advertising_daily_stats (
    day date NOT NULL,
    advertisement_id char(32) NOT NULL,
    placement_id varchar(64) NOT NULL,
    impressions bigint NOT NULL DEFAULT 0 CHECK (impressions >= 0),
    clicks bigint NOT NULL DEFAULT 0 CHECK (clicks >= 0),
    PRIMARY KEY (day, advertisement_id)
);

CREATE TABLE cms_advertising_click_dedupe (
    event_digest char(64) PRIMARY KEY,
    expires_at timestamptz NOT NULL
);

CREATE TABLE cms_advertising_click_window (
    advertisement_id char(32) NOT NULL,
    minute_at timestamptz NOT NULL,
    click_count integer NOT NULL CHECK (click_count BETWEEN 1 AND 600),
    PRIMARY KEY (advertisement_id, minute_at)
);
```

The host should periodically delete expired click digests and rate-window rows older than five minutes. Daily statistic rows are the reporting data; a host retention policy may remove them after its reporting period. These tables contain no visitor identity or request metadata.

## API shape

Admin JSON mutations accept only fields for the selected operation. Creative fields are format-specific:

Placements have the states `active`, `paused` and `archived`. The admin endpoints `POST /admin/advertising/placements/{id}/pause` and `POST /admin/advertising/placements/{id}/activate` pause or reactivate a placement. Pausing suppresses delivery and clicks without changing the states of its ads; after reactivation, active ads are delivered again. Archived placements cannot be reactivated.

- `html_banner`: `creative.html`
- `static_player`: `creative.image_path`, `creative.alt`, `creative.caption`
- `video`: `creative.provider` must be `local`, with `creative.video_path` ending in `.mp4`, `creative.poster_path` ending in `.png`, `.jpg`, `.jpeg` or `.webp`, and `creative.caption`

Every creative also has an absolute HTTPS `destination_url`. Placement IDs use lowercase letters, digits and hyphens. Local asset paths must be root-relative under `/media/`, contain no query, host, backslash or traversal segment, and be served by the host's normal media path.

The output endpoint accepts a placement ID. It emits only safe HTML and records one aggregate impression for each active supported ad. The click endpoint accepts an ad ID and signed token; it does not accept a destination. Statistics are returned as daily rows grouped by ad and placement for a requested date range of at most 367 days.

## Fehlerfälle

- Fehlende Admin-Berechtigung oder ungültiges CSRF-Token: `403`.
- Ungültiges JSON, unbekanntes Format, unsicheres Ziel oder nicht erlaubter Medienanbieter: `400` für HTTP-Eingaben; die Anwendungsschicht verwirft den Wert ebenfalls.
- Unzulässiger Zustandswechsel oder Bearbeitung archivierter Daten: `409`.
- Nicht verfügbare Platzierung oder ungültiger Klicktoken: `404` ohne Weiterleitung.
- Ausfall von Datenbank oder Statistikschicht: keine Ersatzweiterleitung und keine behauptete Zählung.
