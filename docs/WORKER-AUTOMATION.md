# Ablauf für automatische Walker-Arbeit

## Zeitplan

Für einen gemeinsamen 15-Minuten-Takt werden vier wiederkehrende Läufe mit je einem Stundenabstand und versetzten Startminuten eingerichtet:

- Minute `:00`
- Minute `:15`
- Minute `:30`
- Minute `:45`

Jeder einzelne Lauf wird stündlich geplant. Zusammen starten die Läufe alle 15 Minuten. Alle vier bearbeiten ausschließlich das Repository `Gravarium/Arcanorum-worker`.

## Verhalten bei jedem Lauf

1. Lies `README.md`, `AGENTS.md`, `docs/QUALITY-GATES.md`, diese Datei und `docs/WORK-PLAN.md`.
2. Prüfe den aktuellen Stand dieses Walker-Repos, Aufgabenbranches, Pull Requests, den exakten HEAD-SHA sowie laufende oder wartende Pflichtprüfungen.
3. Lies vor jeder Codearbeit den gemeinsamen Koordinationsstand `worker-control/worker-state.json` vom Branch `worker-control`.
4. Setze zuerst eine offene Arbeit fort, wenn sie sicher fortgesetzt werden kann. Sonst nimm die nächste freigegebene, offene und nicht blockierte Aufgabe aus `docs/WORK-PLAN.md`.
5. Beginne mit `AW-001`, solange sie im Arbeitsplan auf `bereit` steht. Wenn es keine freigegebene Aufgabe gibt, keine Produktfunktionen oder Abnahmekriterien erfinden und keine Scheinänderung erzeugen.
6. Arbeite auf einem Aufgabenbranch und stelle Änderungen als Pull Request bereit. Worker-Aufträge mergen nicht und deployen nicht.
7. Führe alle für den exakten HEAD vorgeschriebenen Prüfungen aus. Ein fehlender oder übersprungener Pflichtlauf gilt nicht als bestanden.
8. Aktualisiere Arbeitsplan und Übergabe mit dem tatsächlichen Ergebnis, SHA und Prüfnachweis.

## Gemeinsame Sperre für mehrere Konten

Der Branch `worker-control` enthält eine gemeinsame Datei `worker-state.json`. Sie koordiniert die KI-Konten und bleibt getrennt vom Produktcode und vom Hauptzweig dieses Walker-Repos.

- Jede wiederkehrende Aufgabe erhält bei ihrer Einrichtung eine feste, eindeutige `worker_id`, die in ihrem eigenen Aufgabenprompt gespeichert bleibt. Verwende dafür Konto-Rolle plus Zeitfenster, zum Beispiel `walker-A-00`, `walker-A-30`, `walker-B-15` oder `walker-B-45`. Der GitHub-Anmeldename ist keine KI-Kennung: Mehrere KI-Konten dürfen denselben GitHub-Zugang verwenden.
- Jeder einzelne Lauf erhält zusätzlich eine einmalige `run_id` und schreibt sie zusammen mit `worker_id` in den Koordinationsstand. Verwende dieselbe `worker_id` bei späteren Wiederholungen derselben Aufgabe, aber immer eine neue `run_id` je Ausführung.
- Vor dem Programmieren muss der Lauf die Datei vom Branch `worker-control` samt aktuellem Blob-SHA lesen.
- Bei `status = "claimed"` darf kein neuer Lauf anfangen, selbst wenn GitHub denselben Anmeldenamen zeigt oder die `worker_id` gleich ist. Nur die Ausführung, deren `worker_id` und `run_id` exakt im Koordinationsstand stehen, darf den beanspruchten Auftrag fortsetzen.
- Eine neue oder fortzusetzende Ausführung wird durch Aktualisieren der Datei mit genau dem zuletzt gelesenen Blob-SHA beansprucht. Gelingt die Aktualisierung wegen eines zwischenzeitlichen Commits nicht, liest der Lauf die Datei erneut und arbeitet nicht weiter, wenn ein anderer Anspruch eingetragen ist.
- Vor jeder weiteren Änderung an einem Aufgabenbranch prüft der Lauf erneut, dass `worker_id` und `run_id` weiterhin ihm gehören. Bei verlorenem Anspruch stoppt er sofort und schreibt nichts weiter.
- In der Datei stehen mindestens Aufgaben-ID, `worker_id`, `run_id`, Aufgabenbranch, PR, Beanspruchungszeit, letzter Heartbeat, letzter echter Fortschritt-SHA, Prüfstatus und Blocker.
- Heartbeat und echter Fortschritt sind getrennt. Ein Heartbeat verlängert die Sperre, zählt aber nicht als erledigte Arbeit.
- Während längerer Schritte aktualisiert der aktive Lauf den Heartbeat spätestens alle 10 Minuten und nach jedem abgeschlossenen Prüfblock.
- Ist ein Heartbeat mindestens 15 Minuten alt, prüfe den Aufgabenbranch, den PR- und CI-Stand sowie den Status der eigenen geplanten Ausführung, soweit die Plattform ihn anzeigt. Wenn ein Lauf oder eine Pflichtprüfung aktiv ist, bleibt die Sperre bestehen. Wenn kein Lauf und keine Prüfung aktiv sind, kann dieselbe Aufgabe ab dem letzten bestätigten Stand mit einer neuen `run_id` übernommen werden. Kann der Lauf den Ausführungsstatus nicht prüfen, darf er nicht parallel weiterarbeiten; er hält den Blocker fest und prüft beim nächsten geplanten Lauf erneut.
- Bei einem Blocker dokumentiere den konkreten Grund. Gib die Sperre erst frei, wenn der Arbeitsstand gesichert, der Blocker im Plan festgehalten und kein Prüfjob mehr aktiv ist. Danach darf das nächste unabhängige freigegebene Arbeitspaket beansprucht werden.
- Nach fertigem Auftrag bleibt die Sperre bis zum offenen PR und den bestandenen Pflichtprüfungen bestehen. Danach setze den Status auf `idle`, lösche Anspruch und `run_id`, damit der nächste Lauf übernehmen kann.

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

Die technischen Sicherheits-, Qualitäts- und Testvorgaben aus `AGENTS.md` und `docs/QUALITY-GATES.md` gelten bei jedem Lauf.