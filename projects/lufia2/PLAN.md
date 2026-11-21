# Lufia II – laufender Codex-Auftrag

## Ziel

Rekonstruiere die Spielregeln und Abläufe so gründlich, dass daraus später ein eigenständig programmiertes 2D-Rollenspiel entstehen kann. Gesucht ist eine technische Implementierungsgrundlage, kein Spielerhandbuch. Eine 3D-Umsetzung gehört nicht zum Umfang.

Dieser Plan ist die Arbeitsanweisung für die Fortsetzung des Projekts im Codex-Chat. Er wird mit jedem belegten Fortschritt aktualisiert.

## Arbeitsregeln

- Die USA-Fassung ist die erste Referenz. Japan und Deutschland werden getrennt geprüft.
- Jede Regel braucht konkrete Fundstellen im Referenzcode und einen klaren Belegstatus.
- **Codeverifiziert** bedeutet, dass die relevante Routine im Referenzprojekt als geprüft ausgewiesen ist; die Regel muss trotzdem verständlich nachvollzogen werden.
- **Ablauf nachvollzogen** bedeutet, dass Auslöser, Berechnung und Zustandsänderungen Schritt für Schritt beschrieben sind.
- **Im Original beobachtet** bedeutet, dass das Verhalten an einem konkreten Spielzustand geprüft wurde.
- **Offen** bedeutet, dass die Belege nicht ausreichen.
- Fakten, Schlussfolgerungen und offene Fragen werden sichtbar getrennt. Unbekannte Regeln werden nicht ergänzt oder geraten.
- Für jede fertige Mechanik werden Auslöser, Voraussetzungen, Eingaben und Werte, Berechnung/Ablauf, Ergebnis, Nebenwirkungen, Sonderfälle, Regionen, Quellfundstellen und Belegstatus dokumentiert.
- Referenzquellen dienen dem Verständnis. Kein fremder Quellcode, keine ROM-Dateien und keine extrahierten Originalressourcen werden in dieses öffentliche Repository kopiert. Dokumentiert werden funktionale Regeln und eigener Pseudocode.
- Änderungen bleiben unter `projects/lufia2/`, auf einem Aufgabenbranch und in einem Pull Request. Nicht mergen oder deployen.

## Vollständigkeits-Checkliste

| Bereich | Implementierungsziel | Status |
|---|---|---|
| Quellen- und Versionskarte | Quellstand, ROM-Kennungen, Modulzuordnung und Grenzen der Belege | USA-ROM erkannt und Ressourcencheck bestanden; Japan-ROM erkannt, Regionsanalyse offen; übrige Systeme offen |
| Zufallssystem | Zustand/Seed, Zufallsbereich, Tabellen, Aufrufreihenfolge und Verbrauch | Kernfunktionen in einem temporären C-Harness geprüft; Seedquelle und vollständiger spielweiter Verbrauch offen ([research/rng.md](research/rng.md)) |
| Ahnenhöhle / Ancient Cave | Eintritt, Reset, Stockwerksgenerator, Räume, Verbindungen, Treppen, Objekte, Truhen, Beute, Tod und Verlassen | Phasenfolge, Zähler, Itemfilter, USA-Beutetabellen und Loot-Schwellen geprüft; Graph, Objekttabellen, vollständiger RNG-Trace und Persistenz offen ([research/ancient-cave.md](research/ancient-cave.md)) |
| Quests und Story-Fortschritt | Haupt- und Nebenquests, Questflags, Voraussetzungen, Reihenfolge, Wiederholungen, Belohnungen und Folgen | Flag-/Dialogebenen identifiziert; konkrete Questketten und Ortszuordnungen offen |
| Ereignisse und Rätsel | Auslöser, Bedingungen, Schalter/Flags, Objekte, Rätselzustand, Lösung, Fehlschläge und Folgen je Ort | VM/Caller-Kontexte und USA-ROM-Index geprüft; in der Secret Skills Cave Arrow-Schalter, Säule/Bodenplatte/Tür und RESET im Original beobachtet; übrige Quest-/Rätselzuordnung offen ([research/events-and-puzzles.md](research/events-and-puzzles.md)) |
| Initialisierung, Reset und Wiederherstellung | Spielstart, neues Spiel, Laden, Kartenwechsel, Ereigniszustand, Rast/Heilung, Niederlage, Ahnenhöhle und Wiederaufnahme | Boot-, Feldszene-, Save-/Load-, Recovery-, Karten-Event- und Höhlenroutinen erfasst; komplette Aufrufer-/Lebenszyklusmatrix offen ([research/initialization-and-resets.md](research/initialization-and-resets.md)) |
| Feldbewegung und Kollision | Eingaben, Tile-Prüfung, Bewegung, Übergänge und Sonderfälle | Offen |
| Kampf | Zugreihenfolge, Aktionen, Treffer, Schaden, Status, Gegner-KI und Ergebnis | Offen |
| Gruppe und Wachstum | Erfahrung, Levelgrenzen, Werte, Zufalls-/Tabellenwachstum und Grenzen | Offen |
| Ausrüstung, Gegenstände und Zauber | Nutzung, Ziele, Kosten, Wirkungen, Status und Ausrüstungswechsel | Offen |
| Kapselmonster | Werte, Formen, Entwicklung, Erfahrung, Fähigkeiten und Kampfeinbindung | Erste Codehinweise; Regeln offen |
| Menüs und Speichern | Bedienabläufe, Zustände, Speicherformat und Laden | Offen |
| Regionsvergleich | Abweichungen und Gemeinsamkeiten USA/Japan/Deutschland | USA- und Japan-ROM identifiziert; japanische Adresskarte und Code-/Datenvergleich offen |

Ein Bereich gilt erst als implementierungsbereit, wenn die Regeln mit Quellen belegt, die Zustandsänderungen beschrieben und die noch offenen Ausnahmen ausdrücklich markiert sind. Ein bloßer Dateiname oder eine als geprüft markierte Quellfunktion reicht dafür nicht.

## Reihenfolge der Analyse

1. Die vorhandenen Aussagen in `README.md` gegen den konkreten Stand der Referenzquellen prüfen und eine Quellenkarte erstellen.
2. Zufallssystem und Ahnenhöhle vom RNG-Einstieg bis zu den wesentlichen Ergebnissen verfolgen. Generatorphasen und Reihenfolge der Zufallsaufrufe festhalten.
3. Ereignis-VM, Skriptauslöser und Bedingungsarten rekonstruieren; danach Questketten und Rätsel je Karte mit Voraussetzungen, Zustandsänderungen und Folgen katalogisieren.
4. Initialisierungs- und Resetpfade quer durch Spielstart, Laden, Kartenwechsel, Rast/Heilung, Kampf/Niederlage, Ahnenhöhle und Speicherstände verfolgen.
5. Kampf, Feld und Kollision, Gruppe/Wachstum, Ausrüstung, Gegenstände, Zauber, Kapselmonster, Menüs und Speichern in derselben Belegform rekonstruieren.
6. Japanische und deutsche Fassung gezielt mit der USA-Referenz vergleichen; ungeprüfte Bereiche offen lassen.

## Vorgesehene Forschungsdateien

- `research/source-map.md`
- `research/rng.md`
- `research/ancient-cave.md`
- `research/events-and-puzzles.md`
- `research/quests-and-story.md`
- `research/initialization-and-resets.md`
- `research/field-and-collision.md`
- `research/battle.md`
- `research/party-and-progression.md`
- `research/items-magic-equipment.md`
- `research/capsule-monsters.md`
- `research/menus-and-save.md`
- `research/region-differences.md`

Dateien werden ergänzt, sobald belastbare Befunde vorliegen; leere Platzhalter gelten nicht als Fortschritt.

## Arbeitsprotokoll

Für jeden abgeschlossenen Schritt werden Datum, betroffene Systeme, geänderte Dateien, geprüfter Quellstand, Belegstatus und offene Punkte festgehalten. Prüfergebnisse werden nur genannt, wenn die Prüfung für den konkreten Stand tatsächlich ausgeführt wurde.

### Arbeitsprotokoll am 2026-11-20

- Systeme: Quellenstand, RNG und Ahnenhöhle.
- Geänderte Dateien: `README.md`, `PLAN.md`, `research/source-map.md`, `research/rng.md`, `research/ancient-cave.md`.
- Geprüfter Quellstand: `Lufia2Decomp` `cc7e14744606e2e3b642101e8d440d8cc0e1f98f`; dieser Commit stimmte mit `main` auf GitHub überein. Der Aufgabenbranch stand vor den Änderungen auf `78fcea6ccbf828aa09f04b14949ffbeefe5de0da`.
- Belegstatus: RNG- und Höhleneinstiege sind im Referenz-Metadatenindex `verified`; Kernabläufe sind aus den Fundstellen nachvollzogen. Keine Original-ROM-Beobachtung.
- Tatsächlich ausgeführte Prüfungen: Python `tomllib` zählte 2.305 Funktionsdatensätze mit Status `verified`. Quellaufrufe und Generatorphasen wurden per Textsuche und Quelltextlektüre verfolgt. Keine Laufzeittests oder ROM-Differenztests ausgeführt.
- Offen: Seedherkunft und Aufrufer, ROM-Tabellenwerte, vollständiger bedingter RNG-Trace, Raumgraph-/Formregeln, Exit-/Niederlagen-Persistenz, Japan-/Deutschlandvergleich.

### Ergänzende ROM- und Laufzeitprüfungen am 2026-11-20

- Eingaben: USA-ROM, 2.621.440 Byte, SHA-256 `7c34ecb16c10f551120ed7b86cfbc947042f479b52ee74bb3c40e92fdd192b3a`; Japan-ROM, 2.621.440 Byte, SHA-256 `f62df5ffda3042b7d7717cb49be1029defa0de28191a8c66e380d1c9adbebc6f`. Die dritte Datei wurde als DEGAS-Bitmap erkannt.
- ROM-Prüfung: Das US-spezifische Werkzeug dekodierte 680 von 680 Ressourcen der USA-ROM. Es weist die Japan-ROM wegen des Regionslimits zurück.
- C-Laufzeitfälle: `RandomScale` 65.536, `CaveRandomBelow` 65.536, `CaveRandomIndex` 32.768 und `CaveRandomMean` 458.752 Fälle. Zusätzlich stimmten Seedroutine und erster anschließender Rohbyte für alle 256 Seedbytes bei D=0 mit einer unabhängigen Referenzberechnung überein.
- Grenzen: Der temporäre Harness testete rekonstruierten C-Code gegen Formeln. Er führte keine Original-ROM-Maschinencodepfade aus. `ctest` fand im öffentlichen Referenzcheckout keine Tests. Der strenge GCC-14-Build scheiterte an zwei `-Wimplicit-fallthrough`-Warnungen in `src/battle/battle_targets.c` bei den Zeilen 160 und 170. Der vollständige Release-Build gelang mit `LUFIA2_WARNINGS_AS_ERRORS=OFF`. `python3 scripts/metadata_index.py --check` bestand.
- Offen: ROM-Differenztests, Spielbeobachtungen, Japan-/Deutschlandvergleich, Seed-Aufrufpfad, vollständiger RNG-Verbrauch und Raumgraph.

### Gesamtumfang präzisiert am 2026-11-20

- Auftrag: vollständige technische Analyse des Spiels, einschließlich Quests und Story-Fortschritt, Rätseln und Karteninteraktionen sowie aller Initialisierungs-, Reset-, Heilungs-/Rast-, Lade- und Wiederherstellungspfade.
- Geänderte Dateien: `PLAN.md`, `README.md`, `research/events-and-puzzles.md`, `research/initialization-and-resets.md`.
- Geprüfter Quellstand: Lufia2Decomp `cc7e14744606e2e3b642101e8d440d8cc0e1f98f`.
- Belegstatus: Ereignis-VM-Grundstruktur sowie Reset-/Wiederherstellungsroutinen für Boot, Feldszene, Standarddatensätze, Save/Load, HP/MP-Recovery, Karten-Events und Ahnenhöhle aus den US-Quellen nachvollzogen. Konkrete Questketten, Rätsellösungen, weitere Funktionsaufrufer und die spielweite Lebenszyklusmatrix bleiben offen.
- Prüfungen: gezielte Quelltextlektüre und Abgleich ausgewählter Funktionen mit `metadata/functions.toml`; `git diff --check` ausgeführt. Keine neuen ROM- oder Laufzeitprüfungen in diesem Schritt.

### Ereignis- und Rätselindex am 2026-11-20

- Systeme: Kartenkopf-/Eventtabellen, Feldtrigger, Kartenereignis-VM-Aufrufverträge.
- Geänderte Dateien: `README.md`, `PLAN.md`, `research/events-and-puzzles.md`.
- Geprüfter Quellstand: Lufia2Decomp `cc7e14744606e2e3b642101e8d440d8cc0e1f98f`; bereitgestellte USA-ROM SHA-256 `7c34ecb16c10f551120ed7b86cfbc947042f479b52ee74bb3c40e92fdd192b3a`.
- Belegstatus: Map-Header-Tabelle `$CF:FCBC` lieferte 242 zusammenhängende gültige IDs (0–241), ID 242 war ungültig. Der 3-Byte-Eventzeigerindex ab `$8F:8000` enthielt in diesem Bereich 146 nichtleere Eventblöcke; in den Listen wurden 825 Schlüssel-/Skriptverweise gezählt. Map 5 bestätigt als Probe die Basis `$8F:82D6`. Aufruferregister für Kartenstart, Objekt-/Akteurinteraktion, Zellaktion, Gegenstand, Kampf-Rückkehr, Schrittprobe und ausstehende Trigger wurden verfolgt. Der Bestand sagt noch nicht, welche Orte, Quests oder Lösungen die Skripte darstellen.
- Tatsächlich ausgeführte Prüfungen in diesem damaligen Teilschritt: Quelltextlektüre und direkter, nicht schreibender ROM-Tabellenparser; alle untersuchten Listen endeten mit dem erwarteten Terminator. Kein ROM-Code ausgeführt und noch kein Originalspielablauf beobachtet. Der anschließende Laufzeiteinstieg ist im folgenden Arbeitsprotokoll festgehalten.
- Offen: Karten-IDs Orts-/Szenenbezeichnungen zuordnen, Ereignisskripte und Textskripte in eigene Ablaufbeschreibungen übersetzen, Questflags und konkrete Rätselbedingungen pro Ort zusammenführen, weitere Spielabläufe beobachten und anschließend Japan/Deutschland vergleichen.

### Original-ROM-Laufzeit gestartet am 2026-11-20

- Systeme: Emulatorstart, Speicherplatzauswahl, neuer Spielstart und Dialogeingabe der USA-Fassung.
- Geänderte Dateien: `README.md`, `PLAN.md`, `research/events-and-puzzles.md`.
- Laufzeit: RetroArch 1.20.0 mit BSNES Mercury v0.94 Performance; offizielle Debian-Pakete nur nach `/tmp/lufia-runtime/` entpackt. USA-ROM SHA-256 `7c34ecb16c10f551120ed7b86cfbc947042f479b52ee74bb3c40e92fdd192b3a` direkt geladen; Save-/State-Verzeichnisse lagen ausschließlich unter `/tmp`.
- Beobachtet: vier freie Speicherplätze; „Start“ führte in die erste Dialogszene mit Maxim und Tia. `Return` wählte den Start, `x` (A-Taste laut RetroArch-Belegung) führte den Dialog weiter. Damit sind Spielstart und Tastatureingabe als Laufzeitpfad bestätigt.
- Prüfgrenzen: Nur der Einstieg wurde beobachtet. Keine Questflags, Rätsel, Save/Load-, Reset-, Heilungs- oder Niederlagepfade geprüft. Audio nicht geprüft. Keine ROM-Datei, Emulatorbinary, Save-Datei oder Bildschirmaufnahme im Repository.
- Umgebungsweg: Die CUA-Bridge scheitert weiterhin mit `the gaas-browser node_repl sandbox cannot be disabled`; der bereits laufende X11-Desktop war direkt erreichbar und ließ sich über XTest bedienen. Das löst den Runtime-Blocker für weitere interaktive Beobachtungen.
- Offen: echte Quest-/Rätselabläufe, Reset-/Wiederherstellungsmatrix, tiefergehende Spieltests, Regionsvergleich und weitere Gameplay-Systeme.

### Originalspiel: Secret Skills Cave am 2026-11-21

- Systeme: Feldbewegung und Raumwechsel, Kampf-Grundablauf, Skill-Auswahl und Pfeil, Fernschalter/Brücke, Bodenplatte/Tür sowie Höhlentutorial.
- Laufzeit: USA-ROM mit derselben isolierten RetroArch-/BSNES-Umgebung wie oben. Die 2×-Schnellvorlauftaste wurde beim Navigieren verwendet. Spielstände und Screenshots blieben unter `/tmp/lufia-runtime/`; ein Zwischenstand vor dem offenen Höhlentor liegt dort als `checkpoints/room3-door-open.state`.
- Beobachtet: Der alte Mann schickt den Spieler südlich aus Elcid zur Höhle. In der Secret Skills Cave sagt er, Gegner bewegten sich nur bei Bewegung des Spielers, einzelne Gegner könnten zwei Schritte pro Spielerschritt machen und mehrere Gegner könnten den Spieler einkreisen. Ein Red Jelly wurde mit einem normalen Angriff in einem Treffer besiegt und gab 2 EXP.
- Beobachtet: Im zweiten Raum erklärt der Alte Select zur Skill-Auswahl und Y zum Einsatz von Arrow; der Text sagt, Arrow lähme Gegner vorübergehend, ein Zusammenstoß mit einem gelähmten Gegner starte keinen Kampf und Skills gingen nicht aus. Die Lähmung wurde in diesem Lauf nicht selbst vorgeführt; stattdessen kam es zu einem zweiten Red-Jelly-Kampf.
- Beobachtet: Im Hubraum veränderte ein mit ausgewähltem Arrow getroffener Schalter sichtbar seine Ausrichtung und fuhr eine unterbrochene Brücke aus. Beim späteren Navigieren war die Brücke wieder geschlossen; Auslöser und Dauer dieses Zustandswechsels sind nicht isoliert.
- Beobachtet: Eine Bodenplatte öffnete eine zuvor geschlossene Tür. Nach Verlassen der Platte war die Tür in der unmittelbar folgenden Ansicht noch offen. Der erste Eintrittsversuch ergab eine schwer erkennbare Raum-/Kameraansicht; beim späteren Durchlauf mit der auf der Platte abgestellten Säule passierte Maxim die Tür sichtbar. Die genaue Zielkarte ist noch nicht mit Karten-ID und Ereignisskript verknüpft.
- Beobachtet: Der Spieler konnte eine Säule nur mit gleichzeitigem Aktionsknopf und Richtung bewegen. Von der Südseite nach Norden auf die Bodenplatte geschoben, hielt sie den Durchgang offen, auch nachdem der Spieler die Platte verließ. Der offene Durchgang ließ sich passieren.
- Beobachtet: Der Alte vergab danach den Reset-Zauber. Im Hauptmenü unter „Spell“ stand „Reset“ mit Kosten 0; der Zauber ließ sich ausführen. Danach stand Maxim an einem anderen Punkt desselben Höhlenabschnitts, die Säule war an ihrer Ausgangsposition und der zuvor offene Durchgang war geschlossen. Die MP-Anzeige blieb bei 0/0. Die genaue Rückkehrkoordinate und Gegnerwirkungen sind noch nicht geprüft.
- Bedienungsbefund: Die Select-Schnellwahl zeigt „Arrow“ als Feldwerkzeug; „Reset“ steht in der Spell-Liste des Hauptmenüs. Diese zwei Auswahlwege sind getrennt.
- Grenzen: Die Lähmung durch Arrow, Reset-Verhalten bei Gegnern, sämtliche Raumobjekte und Questflags sowie exakte Koordinaten/Scriptbedingungen sind nicht vollständig geprüft. Die Laufzeitbeobachtung deckt nur den Secret-Skills-Cave-Abschnitt ab und ist kein vollständiger Spieldurchlauf.
- Offen: Höhlenabschnitt nach dem Reset erneut lösen und beenden; weitere Rätsel und Story-Questketten im Original verfolgen; Zustandsänderungen mit USA-ROM-Skripten und Referenzcode verknüpfen.
