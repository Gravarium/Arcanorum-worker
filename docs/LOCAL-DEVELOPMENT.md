# Lokaler technischer Start

## Voraussetzungen

PHP 8.5 mit `pdo_pgsql`, `redis`, `intl`, `mbstring`, `xml` und `zip`, Composer 2, Python 3 und Ruby. Für Coverage wird PCOV benötigt. PostgreSQL 18 und Redis 8 müssen erreichbar sein. Die Container-Anleitung steht in [LOCAL-CONTAINERS.md](LOCAL-CONTAINERS.md).

Alle folgenden Befehle verwenden ausschließlich die künstlichen lokalen Testwerte aus `compose.yaml`. Verwende eine eigene Testdatenbank; die Doctrine-Prüfung richtet ihre Migrations-Metadaten ein und führt vorhandene Migrationen aus. Echte Zugangsdaten gehören in unversionierte Umgebungsvariablen.

```bash
docker compose up -d --wait --wait-timeout 120
export APP_SECRET='local-test-only-secret'
export DATABASE_URL='postgresql://walker_local:local-test-only-password@127.0.0.1:5432/walker_test?serverVersion=18&charset=utf8'
export REDIS_URL='redis://127.0.0.1:6379'
bash scripts/check.sh
```

`scripts/check.sh` beendet sich beim ersten Fehler. Es validiert Manifest und Repository, installiert genau `composer.lock`, führt Composer Audit und PHP-Syntaxprüfung aus, baut Symfony in dev/prod, prüft YAML, Twig, Doctrine und Dienste und führt PHPStan 10 mit Strict Rules, PHP-CS-Fixer Check, Rector Dry-Run, Psalm 1 mit Taintanalyse sowie die vollständige PHPUnit-Suite aus. Leere, unvollständige oder übersprungene Tests sowie fehlende oder ungültige JUnit-, Clover- oder Psalm-Berichte werden abgelehnt. Berichte liegen unter `var/test-results/`.

Die CI verwendet denselben Anwendungsprüfer `scripts/ci/check_application.sh` und dieselbe Berichtsvalidierung. Zusätzlich läuft dort die vollständige Compose-Prüfung in einem eigenen isolierten Projekt. Lokal lässt sie sich separat mit `bash scripts/ci/check_compose.sh` ausführen; sie beendet nur die von diesem Prüflauf gestarteten Testdienste.

## Dienste unabhängig prüfen

```bash
APP_ENV=dev APP_DEBUG=0 php bin/console app:infrastructure:check
APP_ENV=dev APP_DEBUG=0 php bin/console app:infrastructure:check --format=json
```

Exit-Code `0` bedeutet, dass beide Dienste geprüft wurden. `1` bedeutet mindestens einen fehlgeschlagenen Dienst, `2` ein ungültiges Ausgabeformat. Die JSON-Ausgabe enthält ausschließlich die booleschen Felder `database`, `cache` und `ready`. Auch im Debug-Ausgabemodus werden keine Verbindungsadressen, Passwörter oder ursprünglichen Fehlermeldungen ausgegeben.

Die Diagnose prüft die PostgreSQL-Majorversion und eine Leseabfrage. Der Cache-Test verwendet einen zufälligen eigenen Schlüssel mit 30 Sekunden Ablauf und entfernt ihn im `finally`-Block. Die vollständige Testsuite prüft zusätzlich einen parametergebundenen Datenbank-Schreib-/Lesevorgang in einer zurückgerollten Transaktion und den echten Redis-Ablauf.

## Web-Einstieg lokal starten

```bash
APP_ENV=prod APP_DEBUG=0 php -S 127.0.0.1:8000 -t public public/index.php
```

Der Dokumentenstamm ist ausschließlich `public/`. Der PHP-Entwicklungsserver dient nur der lokalen Prüfung. Im Grundgerüst gibt es noch keine fachlichen Routen; eine unbekannte Route liefert HTTP 404 mit einer neutralen Twig-Fehlerseite. Fehlerseiten enthalten keine internen Ausnahmeinformationen.

## Lokale Dienste beenden

```bash
docker compose down --volumes --remove-orphans
```
