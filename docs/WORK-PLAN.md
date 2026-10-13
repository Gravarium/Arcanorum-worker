# Gemeinsamer Walker-Arbeitsplan

Diese Datei ist die gemeinsame Aufgabenquelle für alle KI-Konten. Der Projekteigentümer ergänzt hier Produktaufgaben und ihre Abnahmekriterien. KI-Konten ändern den Umfang oder die Abnahmekriterien nicht eigenmächtig.

## Statuswerte

- `bereit`: freigegeben und noch nicht begonnen.
- `in Bearbeitung`: durch den Koordinationsstand auf dem Branch `worker-control` beansprucht.
- `blockiert`: konkrete Abhängigkeit oder ein externer Fehler verhindert die Arbeit.
- `PR offen`: Änderung ist als Pull Request eingereicht; Pflichtprüfungen laufen oder warten auf Review.
- `erledigt`: alle erforderlichen Prüfungen sind für den endgültigen PR-SHA bestanden und der Projekteigentümer hat die Integration abgeschlossen.

Jeder neue Auftrag braucht eine eindeutige ID, ein Ziel, Abnahmekriterien, Abhängigkeiten und den Status. Bei jedem Statuswechsel sind PR, exakter SHA und Prüfergebnis zu dokumentieren. Ein Status ohne Beleg zählt nicht.

## Freigegebene Startaufgabe

### AW-001 — Technisches PHP-/Symfony-Grundgerüst

**Status:** bereit  
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

## Weitere Aufträge

Der Projekteigentümer ergänzt neue Aufgaben im folgenden Format:

### AW-XXX — Kurzer Titel

**Status:** bereit  
**Abhängigkeiten:** keine

**Ziel:**  
**Abnahmekriterien:**
- [ ] 
**Sicherheits- und Datenhinweise:**  
**Nicht-Ziele:**  
