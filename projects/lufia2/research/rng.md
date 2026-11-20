# Zufallssystem

## Status und Umfang

- RNG-Byte, Skalierung, Seedroutine und Refillroutine sind im Referenzprojekt als `verified` markiert.
- Ablauf der Tabellenzustandsmaschine ist für binäre Arithmetik (D-Flag aus) aus dem Code nachvollzogen.
- Herkunft und ursprüngliche Initialisierung des Seed-Bytes, Aufrufpfad der Seedroutine und vollständiger spielweiter Zufallsaufrufstrom bleiben offen.
- Die Analyse gilt nur für den US-Quellstand `Lufia2Decomp` Commit `cc7e14744606e2e3b642101e8d440d8cc0e1f98f` vom 2026-11-18.

## Zustand

| Zustand | Adresse | Rolle |
|---|---:|---|
| 55-Byte-Tabelle | WRAM `$7E:0521–$7E:0557` | Bytefolge, aus der jede Ziehung liest |
| Seed-Arbeitsbyte | WRAM `$7E:0558` | Eingabe und Arbeitszustand von `Lufia2SeedRandom` |
| nächster Tabellenindex | WRAM `$7E:0559` | Index, der vor dem Tabellenzugriff erhöht wird |
| Seed-Scratch | Direct-Page-Offset `$00` | Wird während des Seedaufbaus ausgeliehen und am Ende wiederhergestellt |

Die Adressen stehen in `src/system/wram.h`. Der Seed-Arbeitsbyte wird innerhalb der Seedroutine überschrieben. In den geprüften Decomp-Dateien wurde kein weiterer C-Aufrufer gefunden, der zeigt, wer ihn vor dem Seedaufbau setzt.

## Rohbyte und Tabellen-Refill

`Lufia2RandomByte` bei `$80:82C7` erhöht den Index, liest danach genau ein Byte aus der Tabelle und gibt es zurück. Wenn der erhöhte Index mindestens 55 ist, wird zuerst die Tabelle erneuert und der Index auf 0 gesetzt. Nach einem normalen Zugriff bleibt der verwendete Index gespeichert.

    index = next_index + 1
    if index >= 55:
        refill(table)
        index = 0
    next_index = index
    return table[index]

Der Refill bei `$80:832D` verändert die 55 Werte der Reihe nach. Für Indizes 0 bis 23 wird der aktuelle Wert mit dem noch nicht überschriebenen Wert 31 Positionen voraus XOR-verknüpft. Danach werden die Indizes 24 bis 54 mit dem bereits erneuerten Wert 24 Positionen zurück XOR-verknüpft. Jeder neue Wert wird sofort geschrieben.

    for i in 0..23: table[i] = table[i] XOR table[i + 31]
    for i in 24..54: table[i] = table[i] XOR table[i - 24]

Das ist eine in-place-Lag-XOR-Folge. Die Reihenfolge der beiden Schleifen ist Teil des Ergebnisses.

## Seedroutine

`Lufia2SeedRandom` bei `$80:82E7` arbeitet folgendermaßen:

1. Statusregister und Scratchbyte werden gesichert. Die Routine setzt M/X auf 8 Bit, löscht das Decimal-Flag aber nicht.
2. Der Seed-Arbeitsbyte wird zunächst an Tabellenposition 54 geschrieben. Der Scratch startet mit 1, der Laufindex mit 0.
3. In 55 Runden wird der Laufindex jeweils um 21 erhöht und bei Erreichen von 55 um 55 reduziert. Damit werden bei binärer Arithmetik alle 55 Positionen genau einmal durchlaufen.
4. Pro Runde wird der bisherige Scratchwert an der aktuellen Position gespeichert. Der Seed-Arbeitsbyte übernimmt den alten Scratchwert; der Scratch erhält die 8-Bit-Differenz aus Seed-Arbeitsbyte minus Scratch.
5. Der nächste Tabellenindex wird auf 54 gesetzt. Die XOR-Refillroutine läuft dreimal über die Tabelle.
6. Das ausgeliehene Scratchbyte und das Statusregister werden zurückgespielt.

Für D=0 lässt sich der Seedteil als folgende 8-Bit-Folge ausdrücken:

    table[54] = work
    scratch = 1
    index = 0
    repeat 55 times:
        index = (index + 21) mod 55
        difference = (work - scratch) mod 256
        previous = scratch
        work = previous
        table[index] = previous
        scratch = difference
    next_index = 54
    repeat 3 times: refill(table)

Ist D=1, werden Indexschritt und Differenz über die Dezimalarithmetik des CPU-Modells ausgeführt. Die Routine löscht das Decimal-Flag nicht. Da in den untersuchten Quellen kein Aufrufer den Status vor dem Seed festlegt, ist die normale Seed-Eingabebedingung noch nicht belegt.

Nach der Seedroutine steht der Index auf 54. Deshalb erneuert der erste nachfolgende Bytezugriff die Tabelle erneut und liest anschließend Position 0. Der Seedaufbau selbst ruft den normalen Bytegenerator nicht auf.

## Skalierung

`Lufia2RandomScale` bei `$80:8299` verbraucht ein Tabellenbyte und berechnet mit der SNES-Multiplikation das obere Byte aus `Grenze × Zufallsbyte`. Für eine 8-Bit-Grenze `n` und Rohbyte `r` ist das Ergebnis:

    floor(n × r / 256)

Das Ergebnis liegt damit für `n > 0` zwischen 0 und `n − 1`. Es gibt keine Modulo-Operation und keine Wiederholungsziehung für einen verworfenen Wert. Die Funktionsdeklaration in `include/lufia2/system.h` dokumentiert dieselbe Produkt-Highbyte-Berechnung.

## Ahnenhöhlen-Hilfsroutinen

`src/cave/cave_random.c` enthält drei eigenständige, im Funktionsindex als `verified` markierte Einstiege. Sie verwenden denselben Rohbytegenerator. Der Eintritt wird nur bei PB `$83`, DP 0, D=0, M=1 und Stack `$1F00–$1FFC` nativ ausgeführt; bei anderem Kontext erfolgt der Handoff an den Original-PC. `RandomBelow` schreibt die Grenze in den vorzeichenbehafteten 8-Bit-Faktor von Mode 7. Bei Grenzen 1 bis 127 ist das Ergebnis `floor(Grenze × Rohbyte / 256)`. Bei Grenzen 128 bis 255 muss die signierte Mode-7-Arithmetik erhalten bleiben: `floor(Rohbyte × int8(Grenze) / 256) mod 256`. Bei Grenze 0 wird trotzdem ein Rohbyte verbraucht und der Multiplikator gelesen; das Ergebnis ist 0.

| Hilfsroutine | Codeablauf | Verbrauch |
|---|---|---:|
| `$83:9E1B` RandomBelow | Mode-7-Produkt für die in A übergebene Grenze, mittleres Ergebnisbyte zurückgeben | 1 Rohbyte |
| `$83:9E11` RandomIndex | RandomBelow, Ergebnis anschließend mal 2 für einen Word-Tabellenoffset | 1 Rohbyte |
| `$83:9DE4` RandomMean | Zwei RandomBelow-Ergebnisse mit 8-Bit-Wrap addieren und rechts schieben | 2 Rohbytes |

RandomMean addiert die zwei bereits skalierten Werte mit 8-Bit-Wrap und verschiebt die Summe danach um ein Bit nach rechts. Die genaue Formel lautet `((wert1 + wert2) mod 256) div 2`. Das ist bei den im Generator gefundenen Grenzen 4 und 9 gleich einem gewöhnlichen abgerundeten Mittelwert. Bei größeren Grenzen kann die Zwischensumme umlaufen.

## Nachvollziehbarer Teil des Höhlen-Zufallsstroms

Der Etagenaufruf `$83:9E31` beginnt vor der Etagenprüfung mit einem variablen Verbrauch. Er bildet `(DP[$40] & 0x1F) + 1` und verwirft so viele Rohbytes. Der genaue semantische Ursprung von DP `$40` in diesem Aufrufkontext ist in den hier geprüften Quellen nicht geklärt.

Danach läuft die belegte Generatorreihenfolge aus `Lufia2CaveBuildFloor`:

| Phase | Ziehungen aus dem Code | Variabilität |
|---|---|---|
| Erste Story-/Scrolltruhe | Rohbyte für Storyversuch, bedingt ein zweites Byte für eine von neun Varianten; bei ausbleibender Platzierung gegebenenfalls RandomBelow(60) für Scrolltruhe | Flags und Storytreffer |
| Acht Truheninhalte | Je belegtem Slot ein Kategorienbyte; danach ein Indexbyte für Listen, Ausrüstung, Verbrauchsgegenstände oder Zauber. Allgemeine Auswahl nutzt zweimal `RandomBelow(9)`. Ein bereits bekannter Zauber führt stattdessen zur allgemeinen Tabelle. | Kategorie, freier Slot und Zauberstatus |
| Raumrechtecke | Pro Versuch RandomBelow(3), danach RandomMean(4) für Breite und bei passender Breite RandomMean(4) für Höhe. Beim Wechsel in die nächste Zeile zusätzlich RandomMean(4). | Ungültige Maße, Rasterende und Raumzahl |
| Doppelte Raumverbindungen | Bei mehreren Verbindungen desselben Raumpaars eine RandomBelow-Ziehung mit Gruppengröße | Zahl der doppelten Verbindungen |
| Start, Treppe und optionale Verbindung | Zellwahl über RandomBelow; zusätzlich ein Rohbyte für die optionale Verbindung und bei Treffer eine weitere Zellwahl | Zahl erreichbarer/markierter Zellen |
| Schatzraum | Rohbyte für den Raumversuch; bei geeigneten 2×2-Kandidaten ein Rohbyte pro Kandidat. 13 Objektversuche mit je zwei Koordinatenziehungen, danach bis zu acht Truhenplatzierungsversuche mit je zwei Koordinatenziehungen, solange die Kapazität nicht erschöpft ist. | Kandidaten, Ablehnungen und Kapazitäten |
| Normale Raumobjekte | RandomBelow(4) bestimmt 4–7 Versuche; je Versuch zwei Jitterziehungen und eine Zellwahl. Eine zusätzliche Zufallsziehung wählt das Objekt, wenn die Platzierung nicht abgewiesen wurde und Kapazität besteht. | Raumzellen, Kollisionen und Objektkapazität |
| Normale Truhen | Je zulässigem 2×2-Feld ein Platzierungsversuch. Bei freier Kapazität zwei Koordinatenziehungen; Kollisionen können den Versuch verwerfen. | Raster, bereits liegende Objekte und Truhenlimit |
| Dekorationen | Rohbyte pro geprüftem Kartenblock; bei Treffer und passendem Dekorationssatz zusätzlich eine skalierte Auswahl | Gezeichnete Zellen und ROM-Tabellen |

Diese Liste beschreibt die im C sichtbaren Zufallsstellen, aber noch keinen vollständigen reproduzierbaren Ziehungs-Trace. Dazu fehlen unter anderem alle Tabelleninhalte, die konkrete Eingabe für DP `$40`, einige von Zell- und Raumzuständen abhängige Schleifenpfade und ein geprüfter Seed-Aufrufpfad.

## Laufzeitprüfung der rekonstruierten C-Helfer

Ein temporärer Test-Harness führte die kompilierten RNG-Funktionen aus `Lufia2Decomp` Commit `cc7e14744606e2e3b642101e8d440d8cc0e1f98f` aus. Die USA-ROM wurde in den Bus eingebunden. Die Tabellenadresse `$83:9F49` wurde dabei auf Datei-Offset `$19F49` und Byte `0x3B` abgebildet.

- `RandomScale`: alle 65.536 Kombinationen aus Grenze und Rohbyte.
- `CaveRandomBelow`: alle 65.536 Kombinationen aus Grenze und Rohbyte, einschließlich der signierten Grenzen 128 bis 255.
- `CaveRandomIndex`: 32.768 Kombinationen mit Grenzen 0 bis 127 und allen Rohbytes.
- `CaveRandomMean`: 458.752 Kombinationen für Grenzen 0, 1, 4, 9, 127, 128 und 255 sowie alle Rohbytepaare.
- `SeedRandom`: alle 256 Seedbytes bei D=0, einschließlich Tabellenvergleich nach drei Refills und des ersten Rohbytes nach dem anschließenden Refill.

Alle Fälle stimmten mit einer unabhängigen Berechnung der dokumentierten Formeln überein. Tabellenindex, Stack und Datenbank-Rückgabe wurden ebenfalls geprüft. Die RNG-Helfer lasen während dieser Funktionsfälle keine Bytes aus der ROM. Der Harness führte daher die rekonstruierten C-Routinen aus, nicht die Original-Maschinencodefunktionen. Das ist kein Original-ROM-Differenztest und keine Beobachtung im Spiel.

## Weitere aus dem Code abgeleitete Prüffälle

Die folgenden Vorhersagen sind nicht in diesen C-Helferprüfungen enthalten und wurden nicht am Originalspiel ausgeführt:

- RandomScale mit Grenze 5 und Rohbyte 0 ergibt 0; Rohbyte 255 ergibt 4.
- RandomMean mit Grenze 4 und Zwischenergebnissen 0 sowie 3 ergibt 1. Mit Zwischenergebnissen 200 sowie 100 ergibt die 8-Bit-Summe 44 und danach 22.
- Cave-RandomBelow mit Grenze `0x80` und Rohbyte `0xFF` ergibt über den signierten Mode-7-Faktor `0x80`, nicht den Wert aus einer vorzeichenlosen Produktformel.
- Ein Indexstand von 53 führt beim nächsten Rohbytezugriff zu Tabellenposition 54 ohne Refill. Ein Stand von 54 führt zu Refill und danach zu Position 0.
- Unter der Annahme D=0 muss der Seedlauf 55 verschiedene Tabellenpositionen besuchen, am Ende Index 54 setzen und dreimal refillen.
- Ein Etagenaufruf mit DP `$40` niederwertig `0x00` verwirft ein Rohbyte; mit niederwertig `0x1F` verwirft er 32 Rohbytes.

## Offene Punkte

- Wer setzt `$7E:0558`, wann wird `$80:82E7` aufgerufen und welcher Zustand wird beim Laden eines Spielstands wiederhergestellt?
- Welche CPU-Statusbedingungen gelten beim Seedaufruf tatsächlich?
- Welche anderen Spielsysteme können den Tabellenindex zwischen zwei Höhlenetagen verändern?
- Exakte Ziehungsreihenfolge für alle bedingten Generatorpfade und Tabellenwerte.
- Regionsabweichungen und Beobachtungen an einer ROM.
