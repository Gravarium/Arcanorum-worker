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

**Status:** PR offen
**Koordination:** `worker-control/worker-state.json`, PR #5
**Prüfstand:** Branch `aw-001/symfony-foundation-a00`, HEAD `645eb88505ead0874b1c1740831f6be7793a24f5`; gegenüber `main` (`5e55acae49ca2ecaf8bc71138b401fab43a8ac6a)) 14 Commits voraus, 0 dahinter. GitHub Actions Lauf [39049173317](https://github.com/Gravarium/Arcanorum-worker/actions/runs/39049173317) auf exakt diesem SHA erfolgreich; alle Pflichtschritte bestanden, keine übersprungen. Berichte: `walker-testberichte-645eb88505ead0874b1c1740831f6be7793a24f5` (bis 24. Oktober 2026).
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