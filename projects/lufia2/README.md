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

- **Lufia2Decomp:** portable semantische Rekonstruktion der US-SNES-Spiel-Logik in C. Das Projekt führt 2.305 Funktionen als geprüft. Es rekonstruiert beobachtbares Verhalten und ist keine bytegenaue Original-Disassemblierung.
- **Lufia2SNESRecomp:** nativer PC-Recompiler für die US-Fassung. Er führt das ursprüngliche 2D-Spiel aus; er ist keine 3D-Umsetzung.
- Die Quellbereiche umfassen unter anderem Actor/Objekt-VM, Kampf, Höhle, Feld und Ereignisskripte, Gegenstände, Menüs, Gruppe, System, Text, Titel und Weltkarte.

Quellen: [Lufia2Decomp](https://github.com/Cellenseres/Lufia2Decomp), [Architekturübersicht](https://github.com/Cellenseres/Lufia2Decomp/blob/main/docs/ARCHITECTURE.md), [Lufia II Recompiled](https://github.com/Cellenseres/Lufia2SNESRecomp).

## Systeme und Analysefortschritt

| System | Zu erfassende Regeln | Status |
|---|---|---|
| Feldsteuerung und Kollision | Bewegung, Tile-Prüfung, Laufzustände, Übergänge | Offen |
| Ereignisse und Rätsel | Schalter, Flags, Objekte, Bedingungen, Ausgänge und Reihenfolge | Quellenbereich bekannt; Rätselregeln noch nicht systematisch erfasst |
| Kampf | Zugreihenfolge, Aktionen, Schaden, Treffer, Status, Gegnerverhalten, Belohnungen | Quellroutinen gefunden; vollständige Regelbeschreibung offen |
| Gruppe und Wachstum | Erfahrungskurven, Levelaufstieg, Grundwerte, Zufalls-/Tabellenwachstum, Grenzen | Quellroutinen für Erfahrung und Stat-Wachstum gefunden; Formeln noch auszuarbeiten |
| Zauber, Fähigkeiten und Gegenstände | Kosten, Ziele, Wirkungen, Statusänderungen, Benutzung und Ausrüstung | Offen |
| Kapselmonster | Werte, Formen, Erfahrung, Fähigkeiten, Lernen und Kampfeinbindung | Quellroutinen gefunden; vollständige Regeln offen |
| Ahnenhöhle / Ancient Cave | Eintritt, Gruppenreset, Stockwerksaufbau, Räume, Objekte, Truhen, Beute, Tod und Verlassen | Generator und zentrale Quelldateien identifiziert; Algorithmus noch zu verfolgen |
| Zufallssystem | Seed, Zufallszustand, Tabelle, Zufallsbereich und Aufrufreihenfolge | Routinen für Zufallsbyte, Seed und Tabellennachfüllung identifiziert; Verhalten offen |
| Speichern und Menüs | Speicherformat, Auswahl, Inventar, Statusanzeigen und Bedienabläufe | Offen |
| Regionsvergleich | gemeinsame Regeln sowie Abweichungen USA/Japan/Deutschland | ROMs identifiziert; Code- und Datenvergleich offen |

## Erste Hinweise zur Ahnenhöhle

Die Quellbasis beschreibt den Stockwerksgenerator in src/cave/ancient_cave.c und die Objekt-/Truhenlogik in src/cave/cave_objects.c. Die Konstanten liegen in src/cave/wram.h.

- Der Generator verwendet getrennte 16×16-Zellenraster für Räume und Formen.
- Der Ablauf umfasst Rasteraufbau, Verbindungen, Zeichnen der Zellen sowie Platzierung von Objekten und Truhen.
- Die Item-Auswahl filtert anhand von Feldbenutzbarkeit und Ausschlussflags; unterhalb des Grenzwerts wird aus dem Stockwerkszähler und einem Preisfaktor von 1.000 eine Preisgrenze gebildet. Ab Zählerwert 0x3C wird im Code eine unbegrenzte Preisgrenze gesetzt. Die Abbildung auf die sichtbare Stockwerksnummer muss noch bestätigt werden.
- Es gibt getrennte Beutegruppen und Sonderbehandlungen für Story-/Scroll-Truhen. Chancen und genaue Ziehungsreihenfolge werden erst nach vollständiger Verfolgung der Zufallsaufrufe als Regeln eingetragen.

Diese Punkte sind vorläufige Codebefunde, noch keine abgeschlossene Spielanleitung oder vollständige Generatorbeschreibung.

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

1. USA-Fassung als technische Referenz festlegen und den Regionsvergleich sauber auf Byte-/Codebereiche abbilden.
2. Zufallszahlengenerator und Ahnenhöhlen-Stockwerksgenerator vom Seed bis zu Räumen, Treppen, Objekten und Beute verfolgen.
3. Ereignis-VM und Rätselbedingungen aus Feldskripten systematisch katalogisieren.
4. Kampf, Gruppenwerte, Erfahrung, Ausrüstung, Zauber und Kapselmonster als Implementierungsregeln dokumentieren.
5. Regeln gegen beobachtete Originalabläufe prüfen und die Unterschiede der deutschen und japanischen Fassungen markieren.