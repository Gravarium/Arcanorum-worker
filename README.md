# Arcanorum Walker

Zusätzlicher öffentlicher Arbeitsbereich für das CMS-Projekt.

Keine Zugangsdaten, privaten Schlüssel, Produktionsdaten, Sicherungen oder personenbezogenen Inhalte in dieses Repository einchecken. Die verbindlichen Entwicklungs- und Qualitätsvorgaben stehen in `AGENTS.md` und `docs/QUALITY-GATES.md`.

## Technischer Start

- [Lokale Einrichtung und vollständige Prüfkette](docs/LOCAL-DEVELOPMENT.md)
- [PostgreSQL 18 und Redis 8 mit Docker Compose](docs/LOCAL-CONTAINERS.md)
- `php bin/console app:infrastructure:check --format=json` prüft die konfigurierten Dienste und liefert einen maschinenlesbaren Status.
- `bash scripts/check.sh` führt die vollständige lokale Anwendungsprüfkette aus.
- `public/index.php` ist der Web-Einstieg. Unbekannte Routen liefern eine neutrale Fehlerseite.
