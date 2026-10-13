# Verbindliche Qualitätsprüfungen

Diese Prüfungen gelten für jede Änderung. Sie entsprechen vollständig den technischen Pflichtprüfungen des Hauptprojekts. Ein grüner Dokumentationslauf ersetzt keine PHP- oder Anwendungstests.

## Bei jeder Änderung

- Git-Diff auf Whitespace-Fehler prüfen.
- Versionierte JSON- und XML-Dateien syntaktisch prüfen.
- Workflow-Actions mit vollständigen Commit-SHAs festlegen.
- Geheimnisse, private Schlüssel, lokale Umgebungsdateien, Datenbankdumps, Sicherungen und private Uploads vom Repository fernhalten.
- PHPStan- und Psalm-Baselines sowie Herabsetzungen der Analyse ablehnen.
- GitHub Actions nur mit Leserechten ausführen; keine Produktionsgeheimnisse an Pull-Request-Läufe übergeben.

## Sobald das PHP-Grundgerüst vorhanden ist

Die CI muss alle folgenden Schritte ausführen. Fehlt eine Pflichtdatei, eine Abhängigkeit oder eine Testsuite, schlägt der Lauf fehl.

1. PHP 8.5 und die benötigten Erweiterungen bereitstellen.
2. composer.json streng validieren, composer.lock reproduzierbar installieren und composer audit auf dem gesperrten Stand ausführen.
3. Jede versionierte PHP-Datei mit php -l prüfen.
4. Symfony-Container in dev und prod bauen; YAML und vorhandene Twig-Vorlagen prüfen.
5. Doctrine-Zuordnungen und Migrationen validieren und Migrationen gegen PostgreSQL 18 ausführen.
6. Redis 8 verfügbar machen und den Projektadapter gegen den Dienst prüfen.
7. PHPStan auf Stufe 10 mit phpstan-strict-rules ausführen.
8. PHP-CS-Fixer im Check-Modus mit Diff, Rector ausschließlich mit --dry-run und Psalm mit Fehlerstufe 1 samt Taintanalyse ausführen.
9. Die vollständige PHPUnit-Suite mit Fehler bei Risky Tests und Warnungen sowie JUnit- und Coverage-Bericht ausführen.
10. JUnit-, Coverage- und Psalm-SARIF-Berichte sieben Tage als CI-Artefakte sichern.

Coverage wird berichtet. Ein Grenzwert wird erst nach fachlicher Begründung festgelegt. SQLite ersetzt nicht die PostgreSQL-Migrationsprüfung. Integrationsprüfungen nutzen ausschließlich künstliche Zugangsdaten aus der CI.

## Sicherheitsregressionen

Funktionen brauchen erfolgreiche und abgewiesene Testfälle. Je nach betroffenem Bereich müssen die Tests mindestens folgende Angriffe und Fehler abdecken:

- Anmeldung, Rechteänderung, Sitzungsrotation, Widerruf, Drosselung und privilegierte Wiederherstellung.
- CSRF für schreibende Aktionen sowie Rollen, Eigentum und Modulrechte für Controller, APIs, Downloads und Jobs.
- XSS, SQL-Injection, Host- und Proxy-Manipulation sowie kontextbezogene Ausgabecodierung.
- SSRF gegen lokale, private, Link-Local- und Metadatenadressen, DNS-Wechsel, Redirects, Ports, Protokollwechsel, Antwortgrößen und Zeitlimits.
- Uploads mit irreführendem Dateityp, Traversal, Symlinks, Archivbomben, doppelten Einträgen, Übergröße und ausführbaren Dateien.
- Modulaktivierung, Fähigkeitsentzug, inkompatible Versionen, parallele Aktionen und Zeitfenster zwischen Prüfung und Nutzung.
- Vorschau und geplante Veröffentlichung gegen Rechteumgehung, doppelte Ausführung und Ausfälle externer Anbieter.
- Prüfprotokolle gegen Geheimnislecks sowie sichere Fehlerantworten und Wiederherstellung verschlüsselter Sicherungen.

Sicherheitsprüfungen berücksichtigen die unterstützten Hostingprofile. Wo eine Schutzgrenze nicht verfügbar ist, wird der sichere Ersatzpfad getestet oder die betroffene Aktion gezielt gesperrt.

## Prüfnachweis

Jeder Lauf bezieht sich auf den exakten HEAD-SHA. Fehlermeldungen sollen Job, Prüfschritt, Datei, Zeile und Ursache enthalten. Nicht gestartete oder übersprungene Pflichtprüfungen gelten nicht als bestanden. Es werden keine Tests, Assertions, Rechteprüfungen oder CI-Regeln entfernt, um einen grünen Lauf zu erzwingen.
