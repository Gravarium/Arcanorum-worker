# Ablauf für automatische Walker-Arbeit

## Zeitplan

Für einen gemeinsamen 15-Minuten-Takt werden vier wiederkehrende Läufe mit je einem Stundenabstand und versetzten Startminuten eingerichtet:

- Minute `:00`
- Minute `:15`
- Minute `:30`
- Minute `:45`

Jeder einzelne Lauf wird stündlich geplant. Zusammen starten die Läufe alle 15 Minuten. Alle vier müssen ausschließlich dieses Repository bearbeiten.

## Verhalten bei jedem Lauf

1. Lies `README.md`, `AGENTS.md`, `docs/QUALITY-GATES.md` und den vom Projekteigentümer bereitgestellten Arbeitsplan.
2. Prüfe den aktuellen Branch, Pull Requests, den exakten HEAD-SHA, laufende CI-Prüfungen und erkennbare aktive Bearbeitung.
3. Setze zuerst eine offene Arbeit fort, wenn sie sicher fortgesetzt werden kann. Sonst nimm den nächsten freigegebenen, offenen und nicht blockierten Auftrag aus dem Arbeitsplan.
4. Wenn kein Produktauftrag vorliegt, arbeite nur an ausdrücklich erforderlichen Grundlagen aus den festgelegten technischen Vorgaben. Erfinde keine Produktfunktionen oder Abnahmekriterien.
5. Arbeite auf einem Aufgabenbranch und stelle Änderungen als Pull Request bereit. Worker-Aufträge mergen nicht und deployen nicht.
6. Führe alle für den exakten HEAD vorgeschriebenen Prüfungen aus. Ein fehlender oder übersprungener Pflichtlauf gilt nicht als bestanden.
7. Aktualisiere Arbeitsplan und Übergabe mit dem tatsächlichen Ergebnis, SHA und Prüfnachweis.

## Wiederaufnahme ohne Doppelarbeit

Ein Pull Request allein beweist keine aktive Arbeit. Als Fortschritt zählen nur neue Code-, Test- oder Konfigurationsänderungen mit SHA, ein neuer CI-Lauf mit Ergebnis oder ein konkret veränderter Blocker.

Ist der letzte belegte Fortschritt mindestens 15 Minuten alt, prüfe zuerst, ob ein KI-Lauf oder eine Pflichtprüfung noch aktiv ist. Wenn ja, warte auf dessen Ergebnis und dupliziere die Arbeit nicht. Wenn niemand arbeitet und keine Prüfung läuft, setze dieselbe Aufgabe am letzten bestätigten Stand fort. Beginne kein zweites Paket auf demselben Arbeitsstand.

Dokumentationsänderungen, die nur einen bereits bekannten Status oder unveränderten CI-Lauf wiederholen, sind kein Fortschritt und dürfen keine Wiederanlaufschleife erzeugen.

## Öffentlicher Bereich

Das Repository bleibt öffentlich. Keine Zugangsdaten, Schlüssel, privaten oder personenbezogenen Daten, Sicherungen oder Produktionsdaten einchecken. Anforderungen werden eigenständig umgesetzt. Arbeitsplan, Code, Tests, Commit-Nachrichten und Dokumentation bleiben frei von Namen oder Hinweisen auf nicht genannte Referenzsysteme.

Die technischen Sicherheits-, Qualitäts- und Testvorgaben aus `AGENTS.md` und `docs/QUALITY-GATES.md` gelten bei jedem Lauf unverändert.
