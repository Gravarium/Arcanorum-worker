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
| Quests und Story-Fortschritt | Haupt- und Nebenquests, Questflags, Voraussetzungen, Reihenfolge, Wiederholungen, Belohnungen und Folgen | Offen; Ereignis-VM-Grundlagen werden zuerst beschrieben |
| Ereignisse und Rätsel | Auslöser, Bedingungen, Schalter/Flags, Objekte, Rätselzustand, Lösung, Fehlschläge und Folgen je Ort | VM-Mechanik teilweise nachvollzogen; konkrete Quest- und Rätselketten offen ([research/events-and-puzzles.md](research/events-and-puzzles.md)) |
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
