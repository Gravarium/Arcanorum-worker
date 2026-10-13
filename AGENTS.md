# Arbeitsstandard

## Automatische Planung
- Die vom Projekteigentümer bereitgestellte automatische Planung bestimmt die Reihenfolge der Aufgaben.
- Nach einem fertig geprüften Punkt wird ohne unnötige Pause der nächste offene und nicht blockierte Punkt begonnen.
- Ist ein Punkt blockiert, wird der Grund in der Planung festgehalten und mit dem nächsten bearbeitbaren Punkt fortgefahren.
- Für normale technische Entscheidungen innerhalb eines Auftrags ist keine Rückfrage erforderlich.

## Öffentlicher Arbeitsbereich
- Dieses Repository ist öffentlich. Jeder Commit und jede Datei ist öffentlich einsehbar.
- Zugangsdaten, private Schlüssel, Produktionsdaten, Sicherungen und personenbezogene Inhalte dürfen nicht eingecheckt werden.
- Anforderungen werden mit eigenständigem Quellcode umgesetzt. Quellcode aus fremden Referenzsystemen wird nicht übernommen.
- Aufgaben, Tests, Commit-Nachrichten und Dokumentation enthalten keine Namen oder Hinweise auf nicht genannte Referenzsysteme.

## Unveränderliche technische Vorgaben
- PHP 8.5 und Symfony 7.4 LTS; PostgreSQL 18 und Redis 8 für die vorgesehenen Integrationsprüfungen.
- Sicherheitsprüfungen, Tests, Berechtigungen, CSRF-Schutz, Eingabevalidierung, Ausgabecodierung, Sitzungs- und Uploadschutz dürfen nicht gelockert oder entfernt werden.
- Unbekannte Identitäten, Aktionen und Berechtigungen werden abgelehnt. Fehler in Sicherheitsprüfungen bleiben geschlossen.
- PHPStan läuft auf Stufe 10 mit phpstan-strict-rules. Psalm läuft auf Fehlerstufe 1 mit Taintanalyse.
- Analyse-Baselines, breite Ignore-Regeln und Herabsetzungen der Prüfstufen sind nicht zulässig.
- Abhängigkeiten werden aus der versionierten composer.lock installiert. composer audit ist Pflicht.
- PHP-CS-Fixer läuft im Check-Modus ohne riskante Regeln. Rector läuft ausschließlich mit --dry-run.
- PHPUnit führt die vollständige Testsuite aus. Eine leere Testsuite gilt als Fehler.
- Die CI prüft immer den exakten Pull-Request-HEAD. Ein grüner Lauf für einen anderen SHA zählt nicht.
- Ein roter, fehlender oder übersprungener Pflichtlauf gilt nicht als bestanden. Fehler werden behoben und die betroffenen Prüfungen erneut ausgeführt.
- Keine Produktions- oder Bereitstellungsgeheimnisse in Pull-Request-Läufen.

## Änderungen und Nachweise
- Änderungen entstehen auf Aufgabenbranches und werden über Pull Requests geprüft. Kein direktes Arbeiten auf main, kein Merge und kein Deployment durch Worker-Aufträge.
- Jede Fertigmeldung nennt Branch, Pull Request, exakten HEAD-SHA und tatsächlich ausgeführte Prüfergebnisse.
- Ein Prüfschritt darf nur als bestanden bezeichnet werden, wenn er für diesen SHA wirklich ausgeführt wurde.
