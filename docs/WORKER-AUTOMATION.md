# Ablauf für automatische Walker-Arbeit

## Zeitplan

Für einen gemeinsamen 15-Minuten-Takt werden vier wiederkehrende Läufe mit je einem Stundenabstand und versetzten Startminuten eingerichtet:

- Minute `:00`
- Minute `:15`
- Minute `:30`
- Minute `:45`

Jeder einzelne Lauf wird stündlich geplant. Zusammen starten die Läufe alle 15 Minuten. Alle vier müssen ausschließlich dieses Repository bearbeiten.

## Verhalten bei jedem Lauf

1. Lies `README.md`, `AGENTS.md`, `docs/QUALITY-GATES.md`, diese Datei und den vom Projekteigentümer bereitgestellten Arbeitsplan.
2. Prüfe den aktuellen Hauptstand, Aufgabenbranches und Pull Requests, den exakten HEAD-SHA sowie laufende oder wartende Pflichtprüfungen.
3. Lies vor jeder Codearbeit den gemeinsamen Koordinationsstand `worker-control/worker-state.json`.
4. Setze zuerst eine offene Arbeit fort, wenn sie sicher fortgesetzt werden kann. Sonst nimm den nächsten freigegebenen, offenen und nicht blockierten Auftrag aus dem Arbeitsplan.
5. Wenn kein Produktauftrag vorliegt, arbeite nur an ausdrücklich erforderlichen Grundlagen aus den festgelegten technischen Vorgaben. Erfinde keine Produktfunktionen oder Abnahmekriterien.
6. Arbeite auf einem Aufgabenbranch und stelle Änderungen als Pull Request bereit. Worker-Aufträge mergen nicht und deployen nicht.
7. Führe alle für den exakten HEAD vorgeschriebenen Prüfungen aus. Ein fehlender oder übersprungener Pflichtlauf gilt nicht als bestanden.
8. Aktualisiere Arbeitsplan und Übergabe mit dem tatsächlichen Ergebnis, SHA und Prüfnachweis.

## Gemeinsame Sperre für mehrere Konten

Der Branch `worker-control` enthält eine gemeinsame Datei `worker-state.json`. Sie koordiniert die KI-Konten und bleibt getrennt von Produktcode und `main`.

- Vor dem Programmieren muss eine KI die Datei vom Branch `worker-control` samt aktuellem Blob-SHA lesen.
- Bei `status = "claimed"` darf kein zweites Konto eine andere Aufgabe anfangen. Es prüft den zugehörigen Branch, PR und CI-Stand und wartet, solange eine Bearbeitung oder Pflichtprüfung aktiv ist.
- Eine neue oder fortzusetzende Bearbeitung wird durch Aktualisieren der Datei mit genau dem zuletzt gelesenen Blob-SHA beansprucht. Gelingt die Aktualisierung wegen eines zwischenzeitlichen Commits nicht, liest die KI die Datei erneut und arbeitet nicht weiter, wenn ein anderes Konto den Anspruch übernommen hat.
- In der Datei stehen mindestens Aufgaben-ID, KI-Kennung, Aufgabenbranch, PR, Beanspruchungszeit, letzter Heartbeat, letzter echter Fortschritt-SHA, Prüfstatus und Blocker.
- Heartbeat und echter Fortschritt sind getrennt. Ein Heartbeat verlängert die Sperre, zählt aber nicht als erledigte Arbeit.
- Während längerer Schritte aktualisiert das aktive Konto den Heartbeat spätestens alle 10 Minuten und nach jedem abgeschlossenen Prüfblock.
- Ist ein Heartbeat mindestens 15 Minuten alt, prüfe zuerst, ob noch ein KI-Lauf oder eine Pflichtprüfung aktiv ist. Ist etwas aktiv, darf die Sperre nicht übernommen werden. Ist nichts aktiv, setze dieselbe beanspruchte Aufgabe ab dem letzten bestätigten Stand fort.
- Bei einem Blocker dokumentiere den konkreten Grund. Gib die Sperre erst frei, wenn der Arbeitsstand gesichert, der Blocker im Plan festgehalten und kein Prüfjob mehr aktiv ist. Danach darf das nächste unabhängige freigegebene Arbeitspaket beansprucht werden.
- Nach fertigem Auftrag bleibt die Sperre bis zum offenen PR und den bestandenen Pflichtprüfungen bestehen. Danach setze den Status auf `idle`, damit der nächste Lauf übernehmen kann.

## Wiederaufnahme ohne Doppelarbeit

Ein Pull Request allein beweist keine aktive Arbeit. Als echter Fortschritt zählen neue Code-, Test- oder Konfigurationsänderungen mit SHA, ein neuer CI-Lauf mit Ergebnis oder ein konkret veränderter Blocker.

Dokumentationsänderungen, die nur einen bereits bekannten Status oder unveränderten CI-Lauf wiederholen, sind kein Fortschritt und dürfen keine Wiederanlaufschleife erzeugen. Beginne kein zweites Paket auf demselben Arbeitsstand.

## Dauerbetrieb

- Kein KI-Lauf darf die wiederkehrenden Walker-Prüfungen selbst pausieren, deaktivieren oder löschen.
- Nur der Projekteigentümer darf sie ausdrücklich abschalten.
- Ein leerer Arbeitsplan, ein fertiger Auftrag, ein roter Test, ein technischer Blocker, ein API-Limit oder ein GitHub-Fehler beendet die Prüfung nicht. Halte den konkreten Zustand fest und prüfe beim nächsten geplanten Lauf erneut.
- Bei einem leeren Arbeitsplan keine Produktaufgaben erfinden und keine Scheinänderungen erzeugen; die Prüfung bleibt aktiv.
- Falls die Plattform oder ein Kontolimit eine Aufgabe automatisch pausiert, kann der KI-Lauf diese Plattformentscheidung nicht verhindern. Melde die Pause mit ihrem Grund, damit der Projekteigentümer sie wieder aktivieren kann.

## Öffentlicher Bereich

Das Repository bleibt öffentlich. Keine Zugangsdaten, Schlüssel, privaten oder personenbezogenen Daten, Sicherungen oder Produktionsdaten einchecken. Anforderungen werden eigenständig umgesetzt. Arbeitsplan, Code, Tests, Commit-Nachrichten und Dokumentation bleiben frei von Namen oder Hinweisen auf nicht genannte Referenzsysteme.

Die technischen Sicherheits-, Qualitäts- und Testvorgaben aus `AGENTS.md` und `docs/QUALITY-GATES.md` gelten bei jedem Lauf unverändert.
