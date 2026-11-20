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
| Quellen- und Versionskarte | Quellstand, ROM-Kennungen, Modulzuordnung und Grenzen der Belege | Angelegt; Aussagen noch systematisch zu verifizieren |
| Zufallssystem | Zustand/Seed, Zufallsbereich, Tabellen, Aufrufreihenfolge und Verbrauch | Offen |
| Ahnenhöhle / Ancient Cave | Eintritt, Reset, Stockwerksgenerator, Räume, Verbindungen, Treppen, Objekte, Truhen, Beute, Tod und Verlassen | Erste Codehinweise; Algorithmus offen |
| Ereignisse und Rätsel | Auslöser, Schalter/Flags, Objekte, Bedingungen, Reihenfolge, Lösung und Folgen | Offen |
| Feldbewegung und Kollision | Eingaben, Tile-Prüfung, Bewegung, Übergänge und Sonderfälle | Offen |
| Kampf | Zugreihenfolge, Aktionen, Treffer, Schaden, Status, Gegner-KI und Ergebnis | Offen |
| Gruppe und Wachstum | Erfahrung, Levelgrenzen, Werte, Zufalls-/Tabellenwachstum und Grenzen | Offen |
| Ausrüstung, Gegenstände und Zauber | Nutzung, Ziele, Kosten, Wirkungen, Status und Ausrüstungswechsel | Offen |
| Kapselmonster | Werte, Formen, Entwicklung, Erfahrung, Fähigkeiten und Kampfeinbindung | Erste Codehinweise; Regeln offen |
| Menüs und Speichern | Bedienabläufe, Zustände, Speicherformat und Laden | Offen |
| Regionsvergleich | Abweichungen und Gemeinsamkeiten USA/Japan/Deutschland | ROM-Fassungen identifiziert; Vergleich offen |

Ein Bereich gilt erst als implementierungsbereit, wenn die Regeln mit Quellen belegt, die Zustandsänderungen beschrieben und die noch offenen Ausnahmen ausdrücklich markiert sind. Ein bloßer Dateiname oder eine als geprüft markierte Quellfunktion reicht dafür nicht.

## Reihenfolge der Analyse

1. Die vorhandenen Aussagen in `README.md` gegen den konkreten Stand der Referenzquellen prüfen und eine Quellenkarte erstellen.
2. Zufallssystem und Ahnenhöhle vom RNG-Einstieg bis zu den wesentlichen Ergebnissen verfolgen. Generatorphasen und Reihenfolge der Zufallsaufrufe festhalten.
3. Ereignisabläufe und Rätselbedingungen systematisch untersuchen und als allgemeines, programmierbares Ereignissystem beschreiben.
4. Kampf, Gruppe/Wachstum, Ausrüstung, Gegenstände, Zauber, Kapselmonster, Feld, Menüs und Speichern in derselben Form rekonstruieren.
5. Japanische und deutsche Fassung gezielt mit der USA-Referenz vergleichen; ungeprüfte Bereiche offen lassen.

## Vorgesehene Forschungsdateien

- `research/source-map.md`
- `research/rng.md`
- `research/ancient-cave.md`
- `research/events-and-puzzles.md`
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