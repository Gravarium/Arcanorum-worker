# Gemeinsamer Walker-Arbeitsplan

Diese Datei ist die gemeinsame Aufgabenquelle für alle KI-Konten. Der Projekteigentümer ergänzt hier Produktaufgaben und ihre Abnahmekriterien. KI-Konten ändern den Umfang oder die Abnahmekriterien nicht eigenmächtig.

## Statuswerte

- `bereit`: freigegeben und noch nicht begonnen.
- `in Bearbeitung`: durch den aufgabenspezifischen Koordinationsstand beansprucht.
- `blockiert`: konkrete Abhängigkeit oder ein externer Fehler verhindert die Arbeit.
- `PR offen`: Änderung ist als Pull Request eingereicht; Pflichtprüfungen laufen oder warten auf Review.
- `erledigt`: alle erforderlichen Prüfungen sind für den endgültigen PR-SHA bestanden und der Projekteigentümer hat die Integration abgeschlossen.

Jeder Auftrag braucht eine eindeutige ID, ein Ziel, Abnahmekriterien, Abhängigkeiten und den Status. Bei jedem Statuswechsel sind PR, exakter SHA und Prüfergebnis zu dokumentieren. Ein Status ohne Beleg zählt.

## Freigegebene Startaufgabe

### AW-001 — Technisches PHP-/Symfony-Grundgerüst

**Status:** in Bearbeitung
**Koordination:** `worker-control/worker-state.json`, PR #5
**Abhängigkeiten:** keine

**Ziel:** Das leere Repository in eine startbare, testbare Basis für die folgenden Eigentümeraufträge überführen.

**Umfang:**

- PHP 8.5 und Symfony 7.4 LTS mit Twig einrichten.
- Composer-Manifest und Sperrdatei reproduzierbar erstellen.
- Projektstruktur, `bin/console`, Konfigurationen und Test-Bootstrap ergänzen.
- PostgreSQL 18 über Doctrine für die Integrationstests anbinden.
- Redis 8 über den Symfony-Cache-Adapter einbinden und mit einem echten Hin-und-zurück-Test prüfen.
- Die vorhandenen Konfigurationen für PHPStan, Psalm, PHP-CS-Fixer, Rector und PHPUnit in die installierbare Entwicklungsumgebung integrieren.
- Eine sinnvolle, nicht leere PHPUnit-Basistestsuite anlegen.
- Kurze lokale Einrichtungs- und Testbefehle dokumentieren.

**Abnahmekriterien:**

1. `composer validate --strict`, `composer install` und `composer audit --locked` bestehen.
2. Symfony-Container starten in `dev` und `prod`; YAML- und Twig-Prüfungen bestehen.
3. PostgreSQL 18 ist in CI erreichbar; Doctrine-Zuordnungen und vorhandene Migrationen werden geprüft.
4. Redis 8 besteht einen echten Cache-Schreib-/Lese-/Ablauf-Test über den Projektadapter.
5. PHPUnit führt echte Tests aus und meldet keine leere Suite.
6. PHPStan Stufe 10 samt Strict Rules, Psalm Stufe 1 samt Taintanalyse, PHP-CS-Fixer im Check-Modus und Rector nur mit `--dry-run` bestehen.
7. Der Pflichtjob für die PHP-Anwendung läuft auf dem exakten PR-SHA tatsächlich durch. Ein übersprungener Anwendungslauf gilt nicht als bestanden.
8. Sicherheits-, Datenschutz- und öffentlichen Repository-Regeln aus `AGENTS.md` bleiben unverändert.

**Grenze:** Diese Aufgabe fügt keine fachlichen CMS-Funktionen, Benutzerrollen, redaktionellen Abläufe oder Gestaltung nach einer nicht im Arbeitsplan enthaltenen Vorgabe hinzu. Solche Punkte kommen als eigene Eigentümeraufträge hinzu.

## Parallel freigegebene Teilaufgabe

### AW-002 — Lokale PostgreSQL-/Redis-Umgebung

**Status:** bereit
**Abhängigkeiten:** keine; ausdrücklich parallel zu AW-001
**Koordination:** eigene Sperrdatei `worker-control/worker-state/AW-002.json`
**Erlaubte Dateien:** `compose.yaml`, `scripts/ci/check_compose.sh`, `docs/LOCAL-CONTAINERS.md` und Änderungen an `.github/workflows/quality.yml` ausschließlich für die Compose-Prüfung.

**Ziel:** Eine lokale, reproduzierbare Testumgebung für PostgreSQL 18 und Redis 8 bereitstellen, die neben dem von AW-001 eingerichteten Symfony-Grundgerüst entwickelt werden kann.

**Abnahmekriterien:**

1. Compose verwendet PostgreSQL 18 und Redis 8, passend zu den Versionen im bestehenden Qualitätsworkflow.
2. Dienste sind nur lokal erreichbar, haben brauchbare Healthchecks und verwenden ausschließlich klar als lokal/test-only erkennbare Werte. Keine echten Zugangsdaten oder Produktionsdaten.
3. `scripts/ci/check_compose.sh` validiert die Compose-Konfiguration, startet beide Dienste, wartet auf Bereitschaft, prüft PostgreSQL-Version 18 und Redis `PING`/`PONG` und räumt CI-Dienste auch nach Fehlern auf.
4. Die Repository-CI führt diese Prüfung für jeden PR-SHA aus, unabhängig davon, ob `composer.json` bereits vorhanden ist.
5. `docs/LOCAL-CONTAINERS.md` erklärt Start, Bereitschaftsprüfung und Stoppen der lokalen Dienste.
6. Die vollständige Compose-Prüfung besteht auf dem exakten PR-SHA. Fehlende oder übersprungene Schritte gelten nicht als bestanden.

**Nicht-Ziele:** Keine Symfony-, Composer-, `src/`-, PHPUnit- oder `docs/LOCAL-DEVELOPMENT.md`-Änderungen. AW-002 bearbeitet nur die oben erlaubten Dateien und greift nicht in AW-001s Branch ein.

## Weitere Aufträge

Der Projekteigentümer ergänzt neue Aufgaben im folgenden Format:

### AW-XXX — Kurzer Titel

**Status:** bereit
**Abhängigkeiten:** keine
**Parallel zu:** keine, sofern nicht ausdrücklich festgelegt
**Erlaubte Dateien:**

**Ziel:**
**Abnahmekriterien:**
- [ ]
**Sicherheits- und Datenhinweise:**
**Nicht-Ziele:**

## Zusaetzliche parallelisierbare CMS-Modulpakete (Eigentuemerfreigabe 22.10.2026)

Die folgenden Aufgaben sind unabhaengige, eigenstaendig zu entwickelnde CMS-Module fuer das gemeinsame Projekt. Privater Arcanorum-Code wird weder ausgecheckt noch in den oeffentlichen Walker uebertragen. Der aufgabenspezifische Claim ist vor dem ersten Schreibzugriff Pflicht. Jeder Auftrag erhaelt einen eigenen Branch und PR; der bestehende AW-001- oder AW-002-Branch wird nicht beschrieben. Der stabile AW-001-Arbeitsstand kann als Basis fuer gestapelte PRs benutzt werden; fehlende Pruefgrundlagen sind Blocker, keine Freigabe.

### AW-003 — Eigenstaendiges Anzeigenmodul

**Status:** bereit (Claim vor Beginn erforderlich)
**Abhaengigkeiten:** startbare PHP-/Symfony-Testbasis aus AW-001
**Parallel zu:** AW-001, AW-002, AW-004
**Erlaubte Pfade:** ausschliesslich neue Dateien unter src/Module/Advertising/, tests/Module/Advertising/ und docs/modules/ADVERTISING.md. Keine geteilten Framework-, Composer-, Konfigurations- oder Workflow-Dateien veraendern.
**Ziel:** Anzeigenstammdaten, erlaubte Werbeformen und sichere Aktivierungsablaeufe als spaeter integrierbare, eigenstaendige Komponenten.

**Abnahmekriterien:**
1. Typen fuer streng bereinigte HTML-Banner, statische Player-Anzeigen und Videoanzeigen-Metadaten.
2. Eingabegrenzen, sichere URL-/HTML-Ausgabe und Negativtests fuer JavaScript, fremde Schemes, Script-Attribute, XSS sowie nicht erlaubte Anbieter.
3. Aktivieren, Pausieren, Deaktivieren mit klaren Zustands- und Berechtigungsgrenzen.
4. Auslieferungs-/Klickstatistik ohne IP-Adressen oder identifizierbare Personen, mit Drosselungs- und Duplikatregeln.
5. Positive/negative PHPUnit-Tests, PHPStan Stufe 10, Psalm-Taintanalyse, CS-Fixer, Rector-Dry-Run und restliche unveraenderte Pflicht-CI auf dem exakten HEAD.
6. Ein kleiner, dokumentierter Integrationsvertrag und Fehlerfaelle, ohne private Datenmodellannahmen.

**Nicht-Ziele:** Keine Liveschaltung, kein Bezug zum privaten Hauptquellcode, kein direkter Werbenetzwerkzugriff, keine Veraenderung am Hauptprojekt, kein Merge.

### AW-004 — Eigenstaendige Aufgabenplanung

**Status:** bereit (Claim vor Beginn erforderlich)
**Abhaengigkeiten:** startbare PHP-/Symfony-Testbasis aus AW-001
**Parallel zu:** AW-001, AW-002, AW-003
**Erlaubte Pfade:** ausschliesslich neue Dateien unter src/Module/Scheduling/, tests/Module/Scheduling/ und docs/modules/SCHEDULING.md. Keine geteilten Framework-, Composer-, Konfigurations- oder Workflow-Dateien veraendern.
**Ziel:** Validierte, sichere CMS-Wartungsaufgaben mit Ausfuehrungsschutz und pruefbaren Ergebnissen.

**Abnahmekriterien:**
1. Feste Task-Allowlist, validierte Zeitintervalle/Zeitzonen, aktiv/pausiert/deaktiviert.
2. Berechtigungsgepruefte CRUD-Vertraege ohne freie Shell-Befehle, Admin-Impersonation oder beliebige Jobskripte.
3. Idempotente Ausfuehrung, Sperre gegen Wiederholung/Parallelstarts und fail-closed bei Ausfall.
4. Begrenztes, nicht vertrauliches Ergebnisprotokoll, fehlerfreier Wiederanlauf.
5. Positive und negative PHPUnit-Regressionen; PHPStan 10, Psalm, CS-Fixer, Rector und alle sonstigen Pflichtgates auf dem exakten HEAD.
6. Dokumentierter Integrationsvertrag ohne private Anwendungsdaten.

**Nicht-Ziele:** Keine Produktionscronjobs, keine Datenbanksicherungen, kein Zugriff auf Hauptrepository oder produktive Server, kein Merge.

### Uebergabe und Sicherheitsgrenze

AW-003/AW-004 sind voneinander getrennt und duerfen nur nach Aufgabe-Claim beginnen. Keine zwei KIs schreiben am selben Pfad oder Branch. Kumulative Worker-Git-Historie darf keine rekonstruierbare Kopie des privaten Hauptprojekts enthalten. Die Ergebnisse werden erst nach gesonderter sicherer Integration, exakter CI-Pruefung und Freigabe im privaten Hauptprojekt eingesetzt. Die oeffentliche Worker-CI ersetzt NICHT die noch blockierten privaten Arcanorum-Pflichtpruefungen.
