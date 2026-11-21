# Lufia II – technische Spielsystem-Spezifikation

Dieses Verzeichnis ist der isolierte Arbeitsbereich für die Analyse und spätere Programmierung eines originalgetreuen 2D-Spiels auf Basis der Regeln und Abläufe von Lufia II. Eine 3D-Umsetzung ist vorerst außerhalb des Umfangs.

Der Zweck ist eine technische Implementierungsspezifikation, kein Spielerhandbuch: Regeln, Formeln, Zufallsabläufe, Puzzlebedingungen, Zustandsänderungen und Sonderfälle werden so dokumentiert, dass sie in einer eigenen Laufzeit reproduzierbar umgesetzt und geprüft werden können.

## Arbeitsregeln

- Keine Regel wird als bestätigt eingetragen, wenn sie nur plausibel klingt.
- Jede Regel erhält eine Quellfundstelle und einen Prüfstatus.
- Originalcode, ROM-Bilder, Musik und extrahierte Originalressourcen werden hier nicht abgelegt.
- US, Japan und Deutschland werden als getrennte ROM-Fassungen behandelt; gemeinsame Mechanik wird erst nach einem Vergleich angenommen.

## Belegstatus

- **ROM-identifiziert:** Prüfsumme und Header identifizieren die konkrete Fassung.
- **Codeverifiziert:** Funktion wurde laut Quellprojekt gegen die unterstützte Original-ROM differenziell geprüft.
- **Ablauf nachvollzogen:** Eingaben, Bedingungen, Berechnung und Nebenwirkungen wurden aus dem Code Schritt für Schritt dokumentiert.
- **Im Original beobachtet:** Verhalten wurde in einem konkreten Spielzustand oder einer Aufzeichnung geprüft.
- **Offen:** Daten oder Verhalten reichen noch nicht für eine verlässliche Regelbeschreibung.

Codeverifiziert bedeutet nicht automatisch, dass eine Mechanik bereits in verständlicher Form beschrieben ist.

## ROM-Fassungen

| Fassung | Datenumfang | Prüfsummen der ROM-Daten | Stand |
|---|---:|---|---|
| USA | 2.621.440 Bytes | SHA-1 A89931C1F29B161B8BE717DFAB4A4ADB54B42B84; SHA-256 7C34ECB16C10F551120ED7B86CFBC947042F479B52EE74BB3C40E92FDD192B3A | Exakter Treffer für die von Lufia II Recompiled verlangte USA-Fassung |
| Japan | 2.621.440 Bytes | SHA-1 FB532E487094037ABA6CFFA0F3D922383E73CB5F; SHA-256 F62DF5FFDA3047B2D7717CB49BE1029DEFA0DE28191A8C66E380D1C9ADBEBC6F | Als Estpolis Biography II identifiziert; Vergleich mit USA offen |
| Deutschland | 3.145.728 Bytes ROM-Daten plus 512-Byte-Kopierheader in der Datei | SHA-1 858197F17AC8081BD7D43F4089A75785A2016C6C; SHA-256 6313998B7292543FC429CA5CD64B5F89917842EED0A4BD4678CB01FDFF4FD40F | Als deutsche Fassung identifiziert; Vergleich mit USA offen |

Die ROM-Dateien bleiben außerhalb des öffentlichen Repositorys. Für den Arbeitsstand werden nur technische Kennungen und Prüfsummen notiert.

## Rekonstruktionsbasis

- **Lufia2Decomp:** portable semantische Rekonstruktion der US-SNES-Spiel-Logik in C. Am geprüften Commit `cc7e14744606e2e3b642101e8d440d8cc0e1f98f` weist `metadata/functions.toml` 2.305 Funktionen als `verified` aus. Diese Metadaten wurden gezählt, die private differenzielle ROM-Prüfung wurde in diesem Auftrag nicht wiederholt. Das Projekt rekonstruiert beobachtbares Verhalten und ist keine bytegenaue Original-Disassemblierung.
- **Lufia2SNESRecomp:** nativer PC-Recompiler für die US-Fassung. Er führt das ursprüngliche 2D-Spiel aus; er ist keine 3D-Umsetzung.
- Die Quellbereiche umfassen unter anderem Actor/Objekt-VM, Kampf, Höhle, Feld und Ereignisskripte, Gegenstände, Menüs, Gruppe, System, Text, Titel und Weltkarte.

Quellen: [Lufia2Decomp](https://github.com/Cellenseres/Lufia2Decomp), [Architekturübersicht](https://github.com/Cellenseres/Lufia2Decomp/blob/main/docs/ARCHITECTURE.md), [Lufia II Recompiled](https://github.com/Cellenseres/Lufia2SNESRecomp).

## Systeme und Analysefortschritt

| System | Zu erfassende Regeln | Status |
|---|---|---|
| Feldsteuerung und Kollision | Bewegung, Tile-Prüfung, Laufzustände, Übergänge | Offen |
| Quests und Story-Fortschritt | Voraussetzungen, Flagketten, Reihenfolge, optionale Inhalte, Wiederholungen, Belohnungen und Folgen | Flag-/Dialogebenen identifiziert; konkrete Questketten und Ortszuordnungen offen |
| Ereignisse und Rätsel | Auslöser, Bedingungen, Schalter, Objekte, Reihenfolge, Lösung, Fehlschläge und Folgen | Frischer USA-Spielstart/Dialogeingabe und Tutorialstart der Secret Skills Cave mit Kampf-, Pfeil-/Schalter- und Türmechanik im Original beobachtet; vollständige Rätselzuordnung offen ([Forschung](research/events-and-puzzles.md)) |
| Initialisierung und Reset | Neues Spiel, Laden, Kartenwechsel, Event-/Objektzustand, Rast/Heilung, Niederlage und Wiederaufnahme | Boot-, Feldszene-, Save-/Load-, Recovery-, Karten-Event- und Höhlenroutinen erfasst; kompletter Aufruferpfad offen ([Forschung](research/initialization-and-resets.md)) |
| Kampf | Zugreihenfolge, Aktionen, Schaden, Treffer, Status, Gegnerverhalten, Belohnungen | Quellroutinen gefunden; vollständige Regelbeschreibung offen |
| Gruppe und Wachstum | Erfahrungskurven, Levelaufstieg, Grundwerte, Zufalls-/Tabellenwachstum, Grenzen | Quellroutinen für Erfahrung und Stat-Wachstum gefunden; Formeln noch auszuarbeiten |
| Zauber, Fähigkeiten und Gegenstände | Kosten, Ziele, Wirkungen, Statusänderungen, Benutzung und Ausrüstung | Offen |
| Kapselmonster | Werte, Formen, Erfahrung, Fähigkeiten, Lernen und Kampfeinbindung | Quellroutinen gefunden; vollständige Regeln offen |
| Ahnenhöhle / Ancient Cave | Eintritt, Gruppenreset, Stockwerksaufbau, Räume, Objekte, Truhen, Beute, Tod und Verlassen | Etagenzähler, Phasenfolge, Itemfilter und USA-Beutetabellen geprüft; Graph, Objekttabellen, voller RNG-Trace und Exit-Persistenz offen ([Forschung](research/ancient-cave.md)) |
| Zufallssystem | Seed, Zufallszustand, Tabelle, Zufallsbereich und Aufrufreihenfolge | Vier RNG-Helfer bestanden 622.592 Fälle, die Seedroutine weitere 256; Seedquelle und spielweiter Verbrauch offen ([Forschung](research/rng.md)) |
| Speichern und Menüs | Speicherformat, Auswahl, Inventar, Statusanzeigen und Bedienabläufe | Offen |
| Regionsvergleich | gemeinsame Regeln sowie Abweichungen USA/Japan/Deutschland | USA- und Japan-ROM erkannt; Code- und Datenvergleich offen |

Der Arbeitsauftrag gilt für das **gesamte Spielsystem**. Dazu gehören auch Questketten und konkrete Rätsel pro Ort sowie sämtliche Wege, auf denen Spielzustände initialisiert, zurückgesetzt, gespeichert, geladen, geheilt oder nach einem Szenenwechsel wiederhergestellt werden. Ein allgemeiner VM-Befund gilt nicht als fertige Quest- oder Rätselanalyse.

## Codebefunde zu RNG und Ahnenhöhle

Der Referenzstand wurde auf `cc7e14744606e2e3b642101e8d440d8cc0e1f98f` festgesetzt. Der RNG nutzt eine 55-Byte-Tabelle mit in-place XOR-Refills. Zufallswerte mit Grenze entstehen durch das obere Produktbyte. Die Seedroutine mischt die Tabelle vor dem ersten anschließenden Bytezugriff dreimal vor; dieser Zugriff löst wegen des gespeicherten Index 54 noch einen Refill aus. Herkunft des Seedwerts und Aufrufpfad bleiben offen.

Der Höhlengenerator verwendet getrennte 16×16-Raster. Sein belegter Phasenablauf reicht vom Listenaufbau und den Truhen über Raumplatzierung, Verbindungen und Objekte bis zu Tiles, Dekorationen und Kartenkopf. Feldgegenstände müssen feldbenutzbar, nicht ausgeschlossen und billiger als die Grenze sein. Die Grenze ist intern `1000 × (Etagenzähler + 1)` unterhalb `0x3C`, danach `0xFFFF`. Story-/Scrolltruhen haben eigene Pfade. Die USA-ROM besteht den Ressourcencheck mit 680 von 680 Ressourcen. Die Kardinalitäten der vier direkten Beutetabellen wurden erfasst. Vollständiger Ziehungs-Trace, Graphregeln, Japan-Abgleich und Exit-Persistenz sind offen.

Die Belege, Quellfunktionen und Prüfergebnisse stehen in [Quellenkarte](research/source-map.md), [Zufallssystem](research/rng.md), [Ahnenhöhle](research/ancient-cave.md), [Ereignis-VM/Rätselgrundlagen](research/events-and-puzzles.md) und [Initialisierung/Reset/Wiederherstellung](research/initialization-and-resets.md). Die bereitgestellte USA-ROM wurde auf Kartenkopf- und Eventzeigerlisten untersucht; das ergab 242 Karten-IDs, 146 Eventblöcke und 825 Listenverweise. Ein frischer Originalspielstart und die Eingabe in der ersten Dialogszene wurden mit RetroArch/BSNES beobachtet; Emulator, ROM und Spielstände blieben außerhalb des Repositories. Die kompilierten RNG-Helfer bestanden 622.592 Formelvergleiche und die Seedroutine 256 Seedfälle. Die konkreten Quest-/Rätselabläufe und Reset-/Wiederherstellungspfade werden weiter geprüft.

## Format für jede fertige Mechanik

1. Zweck und Auslöser
2. Voraussetzungen und betroffene Spielzustände
3. Verwendete Tabellen und Werte
4. Berechnung oder Ablauf in Pseudocode
5. Ergebnis und Nebenwirkungen
6. Grenzen, Sonderfälle und Fehlerverhalten
7. Abweichungen zwischen USA-, Japan- und Deutschland-Fassung
8. Quellfundstelle und Belegstatus
9. Prüffälle, mit denen eine spätere Implementierung gegen das Original verglichen wird

## Nächste Arbeitsschritte

1. Fehlende RNG-Eingabequelle und Referenz-ROM-Daten für einen Seed-bis-Ergebnis-Trace klären.
2. Raumgraph, Zellformen, Tabellenwerte und Exit-/Defeat-Persistenz der Ahnenhöhle vollständig verfolgen.
3. Karten-IDs Orts-/Szenenbezeichnungen zuordnen und Ereignis-/Textskripte systematisch in Quest- und Rätselabläufe übersetzen.
4. Kampf, Gruppenwerte, Erfahrung, Ausrüstung, Zauber und Kapselmonster als Implementierungsregeln dokumentieren.
5. Regeln gegen beobachtete Originalabläufe prüfen und die Unterschiede der deutschen und japanischen Fassungen markieren.
