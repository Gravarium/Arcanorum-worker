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
2. Prüfe offene Aufträge, aktive aufgabenspezifische Sperren, Aufgabenbranches, Pull Requests, den exakten HEAD-SHA sowie laufende oder wartende Pflichtprüfungen.
3. Wähle nur eine `bereit`-Aufgabe, die deine Konto-Rolle übernehmen darf. Lies genau die für diese Aufgabe in `docs/WORK-PLAN.md` angegebene Sperrdatei auf dem Branch `worker-control`.
4. Setze zuerst deine eigene, sicher fortsetzbare Arbeit fort. Sonst nimm die nächste freigegebene, nicht blockierte Aufgabe, für die kein aktiver Anspruch besteht.
5. Beginne mit `AW-001`, solange sie `bereit` ist. `AW-002` darf gleichzeitig laufen, weil der Arbeitsplan sie ausdrücklich parallel freigibt und getrennte Dateien festlegt. Bei späteren Aufgaben ist parallele Arbeit nur erlaubt, wenn der Arbeitsplan sie ausdrücklich freigibt und die erlaubten Dateien sich nicht überschneiden.
6. Erfinde keine Produktfunktionen oder Abnahmekriterien. Wenn keine passende freigegebene Aufgabe bereit ist, halte den Grund fest; die wiederkehrende Prüfung bleibt aktiv.
7. Arbeite auf einem Aufgabenbranch und stelle Änderungen als Pull Request bereit. Worker-Aufträge mergen nicht und deployen nicht.
8. Führe alle für den exakten HEAD vorgeschriebenen Prüfungen aus. Ein fehlender oder übersprungener Pflichtlauf gilt nicht als bestanden.
9. Aktualisiere Arbeitsplan und Übergabe mit tatsächlichem Ergebnis, SHA und Prüfnachweis.

## Aufgabenspezifische Sperren und parallele Arbeit

Der Branch `worker-control` enthält eine eigene Sperrdatei je Auftrag. Das verhindert Doppelarbeit an derselben Aufgabe und erlaubt ausdrücklich freigegebene, dateiseitig getrennte Aufgaben parallel.

- AW-001 verwendet während der laufenden Bearbeitung die bestehende Datei `worker-control/worker-state.json`.
- AW-002 verwendet ausschließlich `worker-control/worker-state/AW-002.json`.
- Eine Sperre für AW-001 blockiert AW-002 nicht. Die parallele Freigabe und erlaubten Dateien müssen aber ausdrücklich im Arbeitsplan stehen.
- Jede wiederkehrende Aufgabe erhält eine feste, eindeutige `worker_id`, die in ihrem Aufgabenprompt gespeichert bleibt, zum Beispiel `walker-A-00` oder `walker-B-15`. Der GitHub-Anmeldename ist keine KI-Kennung; mehrere KI-Konten dürfen denselben GitHub-Zugang verwenden.
- Jeder einzelne Lauf erhält eine neue `run_id` und trägt `worker_id` und `run_id` in die Sperrdatei der eigenen Aufgabe ein.
- Vor dem Programmieren liest der Lauf die Sperrdatei seiner Aufgabe samt aktuellem Blob-SHA.
- Wenn genau diese Aufgabe bereits von einem anderen Lauf beansprucht ist, darf kein zweiter Lauf daran anfangen. Nur ein Lauf, dessen `worker_id` und `run_id` exakt im eigenen Aufgaben-Lock stehen, darf die Arbeit fortsetzen.
- Eine neue oder fortzusetzende Arbeit wird durch Aktualisieren ihrer Sperrdatei mit genau dem zuletzt gelesenen Blob-SHA beansprucht. Scheitert das Update wegen eines zwischenzeitlichen Commits, Datei erneut lesen und den neuen Stand erhalten. Niemals fremde Felder oder Ansprüche überschreiben.
- Vor jeder weiteren Änderung am eigenen Aufgabenbranch prüfen, dass der Anspruch in der zugehörigen Sperrdatei noch zur eigenen `worker_id` und `run_id` gehört. Bei verlorenem Anspruch sofort stoppen.
- In der Sperrdatei stehen Aufgaben-ID, `worker_id`, `run_id`, Aufgabenbranch, PR, Beanspruchungszeit, letzter Heartbeat, letzter echter Fortschritt-SHA, Prüfstatus und Blocker.
- Heartbeat und echter Fortschritt sind getrennt. Ein Heartbeat verlängert die Sperre, zählt aber nicht als erledigte Arbeit. Während längerer Schritte spätestens alle 10 Minuten und nach jedem Prüfblock aktualisieren.
- Ist der Heartbeat mindestens 15 Minuten alt, prüfe den Aufgabenbranch, den PR- und CI-Stand sowie den Status des eigenen geplanten Laufs, soweit sichtbar. Wenn ein Lauf oder eine Pflichtprüfung aktiv ist, bleibt genau diese Aufgabensperre bestehen. Übernimm dieselbe Aufgabe nur, wenn der vorherige Lauf und alle Prüfungen beendet sind; verwende dann eine neue `run_id`.
- Bei Blockern den konkreten Grund dokumentieren. Nur die eigene Sperre freigeben, wenn der Arbeitsstand gesichert und kein Prüfjob aktiv ist. Sperren anderer Aufgaben nie verändern.
- Nach erfolgreichem Abschluss die eigene Sperre auf `idle` setzen und Anspruch sowie `run_id` löschen, damit der nächste Lauf dieselbe Aufgabe oder einen freigegebenen nächsten Auftrag übernehmen kann.

## Wiederaufnahme ohne Doppelarbeit

Ein Pull Request allein beweist keine aktive Arbeit. Als echter Fortschritt zählen neue Code-, Test- oder Konfigurationsänderungen mit SHA, ein neuer CI-Lauf mit Ergebnis oder ein konkret veränderter Blocker.

Dokumentationsänderungen, die nur einen bekannten Status oder unveränderten CI-Lauf wiederholen, sind kein Fortschritt. Beginne kein zweites Paket auf demselben Arbeitsstand.

## Dauerbetrieb

- Kein KI-Lauf darf wiederkehrende Walker-Aufgaben selbst pausieren, deaktivieren oder löschen.
- Nur der Projekteigentümer darf sie ausdrücklich abschalten.
- Ein leerer Arbeitsplan, ein fertiger Auftrag, ein roter Test, ein technischer Blocker, ein API-Limit oder ein GitHub-Fehler beendet die Prüfung nicht. Halte den Zustand fest und prüfe beim nächsten geplanten Lauf erneut.
- Bei leerem Arbeitsplan keine Produktaufgaben erfinden und keine Scheinänderungen erzeugen; die Prüfung bleibt aktiv.
- Falls die Plattform oder ein Kontolimit eine Aufgabe automatisch pausiert, kann der KI-Lauf diese Plattformentscheidung nicht verhindern. Melde die Pause mit ihrem Grund, damit der Projekteigentümer sie wieder aktivieren kann.

## Öffentlicher Bereich

Das Repository bleibt öffentlich. Keine Zugangsdaten, Schlüssel, privaten oder personenbezogenen Daten, Sicherungen oder Produktionsdaten einchecken. Anforderungen werden eigenständig umgesetzt. Arbeitsplan, Code, Tests, Commit-Nachrichten und Dokumentation bleiben frei von Namen oder Hinweisen auf nicht genannte Referenzsysteme.

Die technischen Sicherheits-, Qualitäts- und Testvorgaben aus `AGENTS.md` und `docs/QUALITY-GATES.md` gelten bei jedem Lauf.