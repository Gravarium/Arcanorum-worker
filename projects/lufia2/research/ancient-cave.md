# Ahnenhöhle / Ancient Cave

## Belegstatus

- `Lufia2AncientCaveGenerateFloor` bei `$83:9E31` und die Eintritts-, Reset-, Niederlage- und Exit-Einstiege sind laut Metadaten im Referenzprojekt `verified`.
- Der Etagenzähler, der erste Generatoraufruf, die Generatorphasen, Itemlistenfilter, Preisgrenze, Truhenrollen und Zufallsformeln sind für den US-Quellstand `cc7e14744606e2e3b642101e8d440d8cc0e1f98f` aus den Aufrufen nachvollzogen.
- Kein Originalspiel wurde in diesem Arbeitslauf gestartet. Alle Etagen- und Loot-Erwartungen unten sind Codebefunde, keine Spielbeobachtungen.
- Die Werte der ROM-Tabellen, der vollständige RNG-Verbrauch jedes möglichen Pfads, vollständige Raumgraphenregeln, Monsterbelegung und Regionsunterschiede bleiben offen.

## Einstieg und Etagenzähler

Die Eintrittsroutine bei `$84:878A` sichert Bereiche des Spielzustands, leert unter anderem den Höhlenzähler bei `$7F:E696` und setzt den Höhlen-Inventarmodus zurück. Sie erfasst blaue Gegenstände aus Inventar und Ausrüstung und ruft anschließend den Gruppenreset bei `$84:8888` auf.

Beim Kartenaufbau in `src/field/field_session_setup.c`, Funktion `LoadSessionMap`, wird dies nur ausgeführt, wenn die Map-ID `0xF0` und die Übergangsquellkarte ungleich 0 ist. Ein Zähler von 0 wird vor dem Aufruf bei `$83:B26D` auf 1 gesetzt; danach ruft die Session `$83:9E31` auf. Nach erfolgreichem Aufbau erhöht `$83:9E31` den Zähler. Die erste auf diesem Pfad generierte Etage erhält somit den internen Zähler 1. Eine unabhängige Prüfung der im Spiel angezeigten Etagenbezeichnung fand nicht statt.

Der Generator verwendet `(Etagenzähler − 1) / 10` zur Auswahl einer von zehn Etagenregionen. Bei Zählerwert `0x63` richtet er stattdessen den Kartenwechsel auf Karte `0xF1`, Zielkoordinaten `(0x0B, 0x2E)` und Parameter `0x40` ein und kehrt vor dem normalen Kartenaufbau zurück. Der genaue im Original angezeigte Abschlusszustand ist hier nicht beobachtet.

Vor dieser Sonderprüfung verbraucht der Generator `(DP[$40] & 0x1F) + 1` Rohbytes. Das macht den Startindex einer Etage von zusätzlichem Aufrufzustand abhängig.

## Generatorreihenfolge

Der in `src/cave/ancient_cave.c` rekonstruierte Etagenaufbau läuft in dieser Reihenfolge:

1. Raum- und Formraster löschen.
2. Feldgegenstände für zwei Höhlenlisten filtern.
3. Story- oder Scrolltruhe im ersten Truhenslot versuchen.
4. Verbleibende Truheninhalte für insgesamt acht 16-Bit-Slots auswählen.
5. Raumrechtecke auf dem 16×16-Raster platzieren.
6. Räume zusammenführen und verbinden, Korridorpaare auflisten und doppelte Raumverbindungen ausdünnen.
7. Startzelle, Treppe und optionale Sonderverbindung setzen.
8. Seltenen Schatzraum und danach reguläre Objekte sowie Truhen verteilen.
9. Zellenformen ableiten, Basiskarte zeichnen und Tileset-/Dekorationsdaten anwenden.
10. Treppen, Verbindungen und Truhen in der Karte markieren und den Kartenkopf aufbauen.

Die zwei Arbeitsraster liegen bei `$7F:EA00` und `$7F:EB00`, je 256 Bytes. Das erste enthält Raum-/Verbindungsmerkmale, das zweite Zellformen. Objekt- und Truhenlisten werden getrennt in WRAM geführt.

## Feldgegenstände und Preisgrenze

`CaveCollectItems` liest Itemdatensätze ab `$96:CF69`. Ein Eintrag kommt nur in eine der beiden Listen, wenn:

- das Feldbenutzbarkeitsbit `0x02` gesetzt ist,
- das Ausschlussbit `0x20` in keinem der zwei Flagbytes gesetzt ist,
- der Preis kleiner als die aktuelle Preisgrenze ist.

Der Vergleich schließt einen Gegenstand mit Preis genau an der Grenze aus. Für Zählerwerte unter `0x3C` wird die Grenze als `1000 × (Etagenzähler + 1)` berechnet. Ab `0x3C` wird sie auf `0xFFFF` gesetzt. Da der Vergleich weiterhin strikt kleiner verlangt, würde auch ein Datensatzpreis `0xFFFF` ausgeschlossen; ob ein solcher Preis in den Itemdaten existiert, ist ohne ROM-Daten offen. Die Listenlängen sind Endoffsets, die an ihrer jeweiligen Basis starten. Für eine leere Liste bleibt die Länge 0. Der Auswahlpfad hat keine sichtbare Leerlistenprüfung: `RandomIndex(0)` verbraucht ein Byte, ergibt Index 0 und liest danach den ersten Listenslot. Ob das mit den Originaldaten erreichbar ist, bleibt offen. Das Use-Bit `0x0001` entscheidet den Listenpfad: Im aktuellen C wird ein gesetztes Bit an Liste A ab Offset 0 angehängt, ein gelöschtes Bit an Liste B ab Offset `0x1000`. Der Konstantenname `ITEM_USE_LIST_B` passt nicht zu diesem Branchverhalten. Die Bedeutung der Listen für konkrete Itemarten und die Flagwerte selbst müssen an den Datensätzen geprüft werden.

Aus dem Code folgen für spätere Prüfungen: Bei Zähler 1 ist die Grenze 2.000, bei 59 ist sie 60.000 und ab 60 ist die Grenze `0xFFFF`. Nur Preise kleiner als diese Grenze werden aufgenommen. Diese Werte sind aus dem Code berechnet, nicht im Original gemessen.

## Raumrechtecke und Verbindungen

Die Rechtecksuche startet mit Spalte 1 und Zeile 4. Pro Versuch wird eine Zeile im Bereich Cursorzeile ±1 gezogen, die Breite ist `RandomMean(4) + 1`. Nur wenn Breite plus Spalte kleiner 9 ist, folgt die Höhe `RandomMean(4) + 1`; Höhe plus Zeile muss kleiner 15 sein. Ein 1×1-Rechteck wird in der Breite erweitert. Die Rasterspalte läuft mit der gewählten Breite weiter. Ist sie mindestens 9, beginnt eine neue Rasterzeile, deren Abstand `RandomMean(4) + 2` beträgt.

Der Zähler für gefüllte Räume wird vor dem Füllen erhöht. Beim Erreichen von 8 beendet die Routine den Aufbau, bevor sie dieses achte Rechteck füllt. Der Codepfad ist daher auf höchstens sieben tatsächlich gefüllte Rechtecke begrenzt. Zusätzlich endet die Suche beim Erreichen des unteren Rasterrands.

Die Verbindungsphase führt acht Raum-Merge-Durchläufe aus, zählt die belegten Zellen und wählt zufällige Zellen, um Raumverbindungen zu verfolgen. Benachbarte Zellen werden auf mögliche horizontale, vertikale und diagonale Korridoröffnungen geprüft. Danach wird erneut verbunden und eine Liste von Zellenpaaren aufgebaut. Wenn dasselbe Raum-ID-Paar mehrere Verbindungen hat, wählt der Generator zufällig eine davon und entfernt die weiteren. Die genauen Merge- und Erreichbarkeitsregeln sind noch nicht in vollständiges Implementierungspseudocode überführt. Die Zellwahl zählt belegte, nicht markierte Zellen, zieht einen Index unter dieser Anzahl und sucht die entsprechende Zelle in der 16×16-Rasterfolge. Für Gruppen doppelter Verbindungen wird eine Verbindung zufällig ausgewählt und die übrigen aus der Liste entfernt.

## Start, Treppe und Sonderverbindung

Die Startposition wird aus belegten, noch nicht markierten Zellen gewählt und in Kartenkoordinaten umgerechnet. Anschließend markiert die Routine Endpunkte der gespeicherten Verbindungen. Eine weitere Zufallszellwahl bestimmt die Treppe. Wenn keine passende unverbrauchte Zelle gefunden wird, löscht der Code die Markierungen und versucht die Zellwahl erneut.

Danach werden die Markierungen für eine optionale Verbindung geleert. Ein Rohbyte unter `0x10` löst eine weitere Zellwahl aus und setzt die beiden Koordinaten der Sonderverbindung. Die genaue Darstellung und Wirkung dieser Verbindung im Spiel muss noch geprüft werden.

## Story-, Scroll- und normale Truhen

Story- und Scrolltruhen werden vor den normalen Slots geprüft. Der Storyversuch zieht ein Rohbyte und gelangt bei Werten 0 bis 4 in die Variantenprüfung. Eine von neun Varianten wird gewählt. Bitflags und Storyeventflags verhindern, dass bereits erledigte Storygegenstände erneut als Truhe erscheinen. Bei erfolgreicher Platzierung wird der erste Slot belegt und die restlichen Inhalte beginnen beim nächsten 16-Bit-Slot.

Wenn keine Storytruhe platziert wird, prüft der Code einen Scrolltruhenstatus. Eine Scrolltruhe wird erst ab Etagenzähler 21 versucht. Der Generator zieht einen Wert aus `0..59` und platziert die Truhe, wenn der Wert kleiner als der Etagenzähler ist. Der Status kann den Versuch sperren. Ab Zähler 60 wäre der Zahlenvergleich immer erfolgreich, sofern der Status den Ablauf zulässt.

Für die übrigen Slots wird jeweils ein Rollbyte ausgewertet. Die geschachtelten Grenzvergleiche ergeben folgende disjunkten Bereiche:

| Rollbyte | Auswahlpfad | Folgeauswahl |
|---:|---|---|
| 174–255 | Liste A | Zufälliger Eintrag der gefilterten Liste A |
| 129–173 | Liste B | Zufälliger Eintrag der gefilterten Liste B |
| 99–128 | Zauberrolle | Bereits bekannter Zauber wird durch allgemeinen Gegenstand ersetzt |
| 94–98 | Ausrüstung | Index aus 41 Einträgen |
| 36–93 | Allgemeiner Gegenstand | Mittelwert zweier Ziehungen mit Grenze 9 |
| 0–35 | Verbrauchsgegenstand | Index aus 31 Einträgen |

Die Kategorien und Grenzwerte stammen aus `src/cave/wram.h`; die Vergleiche und Folgeroutinen stehen in `CaveChestContents`. Zauberlisten umfassen 35 IDs. Die Auswahlwerte der Item-, Ausrüstungs-, Verbrauchs- und Storytabellen sind ROM-Adressen und im Quellrepository nicht als Daten enthalten. Gewichtungen für einzelne Itemidentitäten sind daher offen.

## Objekt- und Truhenplatzierung

Objektkapazität ist 20 Einträge. Ein seltener 2×2-Schatzraum wird bei einem Rohbyte unter `0x10` gesucht. Für passende belegte Rasterkandidaten folgt ein weiterer Rohbyte, der unter `0x80` liegen muss. Bei Erfolg versucht der Code 13 Objekte mit je zwei zufälligen Koordinaten und bis zu acht Schatztruhen. Danach werden reguläre Raumobjekte versucht: Die Zahl der Versuche ist `RandomBelow(4) + 4`, also vier bis sieben. Pro Versuch werden zwei Jitterwerte gezogen und eine Zelle gewählt. Der Eintrag wird nur gesetzt, wenn Zellwahl, Näheprüfungen und Kapazität es erlauben. Ein zusätzliches Zufallsbyte wird mit Grenze 6 gezogen und mit `2 × (Etagenzähler − 1)` zum ROM-Tabellenindex kombiniert. Die Einträge werden anschließend an `$94:D95C`, `$94:DDF6` und zugehörigen Objektmetadaten nachgeschlagen. Die konkrete Tabellenbedeutung und die resultierenden Objekt-IDs bleiben ohne ROM-Daten offen.

Das Höhlenlimit beträgt acht Truhen insgesamt. Reguläre Platzierungen ziehen, solange noch Platz besteht, zwei Koordinatenwerte. Die Position wird verworfen, wenn sie nahe Start oder einem Objekt liegt, die Treppe belegt oder eine bestehende Truhe in der geprüften Nachbarschaft liegt. Ablehnungen ändern die Zahl der Ziehungen des weiteren Ablaufs.

Dekorationen werden beim Zeichnen blockweise geprüft. Pro Block wird ein Rohbyte mit `0x30` verglichen. Bei passendem Wert und passendem Tileset wird eine zusätzliche Zufallsauswahl aus der in ROM gelesenen Dekorationsvariante gezogen. Die tatsächlichen Tiles, Varianten und dekorierten Ergebnisse sind ohne ROM-Daten nicht rekonstruierbar.

## Niederlage, Verlassen und persistenter Zustand

Die Routinen `$84:8B9C` und `$84:890B` sind als `verified` markiert. Beim Eintritt werden ausgewählte blaue Gegenstände aus Inventar und Ausrüstung in eine gespeicherte Liste übernommen. Der Höhlen-Inventarmodus startet mit 0. Der Eintritt sichert außerdem 1.931 Bytes Spielzustand, bevor der Höhlenzustand eingerichtet wird.

Die beiden Exitpfade sind im Code klar getrennt:

| Auslöserzustand | Modus | Ablauf vor/bei der Rückgabe |
|---|---:|---|
| Normales Verlassen | 0 | Der Exit sammelt die vorgesehenen tragbaren Gegenstände aus dem Höhleninventar und der Ausrüstung in der Carry-Liste, stellt den gespeicherten Spielzustand wieder her und gibt danach diese Carry-Liste zurück. |
| Niederlage | `0xFF` | Die Niederlage setzt den Modus und ruft den Gruppenreset auf. Beim späteren Exit wird die Höhlen-Carry-Liste nicht neu gesammelt. Nach Wiederherstellung des gesicherten Zustands gibt der Exit die beim Eintritt gespeicherte Itemliste zurück. |

Die Gruppenreset-Routine gibt die gespeicherten Anfangsgegenstände nur aus, wenn der Modus 0 ist. Der Niederlagepfad erhöht den Defeatzähler und sättigt ihn bei `0xFFFF`. Beim Exit führt der Minutenübertrag zu einer Stundenerhöhung; die anschließende Addition der Spielstunden sättigt bei Überlauf auf `0xFF`. Der 16-Bit-Laufzähler wird bei Überlauf auf `0xFFFF` gesättigt. Die Eintrittszahl wird ebenfalls als 16-Bit-Zähler erhöht und bleibt bei Überlauf auf `0xFFFF`.

Diese Zweige beschreiben die Quellenlogik und sind nicht im Original beobachtet. Die genaue Auswahl aller Gegenstände für die Carry-Liste, Zusammenführung mit Stapeln und Verhalten bei vollem Inventar sind noch offen. Eine ROM-Prüfung der sichtbaren Folgen und der Persistenz nach Laden eines Spielstands wurde nicht ausgeführt.

## Quellen und offene Prüfpunkte

Hauptquellen im Referenzcommit `cc7e14744606e2e3b642101e8d440d8cc0e1f98f`:

- `src/cave/ancient_cave.c`: `$83:9013–$83:99C7` Aufbau, `$83:9E31` Etagenübergang und Zähler.
- `src/cave/cave_random.c`: `$83:9DE4`, `$83:9E11`, `$83:9E1B` Höhlenzufall.
- `src/cave/cave_objects.c`: `$83:99C8–$83:9B17` Objekt- und Truhenplatzierung.
- `src/cave/cave_grid.c`: `$83:9B18–$83:9D38` Raster- und Zellwahlroutinen.
- `src/cave/cave_entry.c`, `src/cave/cave_reset.c`, `src/cave/cave_exit.c`: Eintritt, Reset, Niederlage und Exit.
- `src/field/field_session_setup.c`: erster Etagenaufruf bei `$83:B26D`.
- `src/cave/wram.h`, `src/system/wram.h`: Speicheradressen und Generatorgrenzen.

Noch offen sind insbesondere der vollständige Raumgraph- und Formalgorithmus, alle ROM-Tabellen, vollständiger RNG-Trace für konkrete Zustände, Gegner- und Truhen-Originalbeobachtungen, Tod/Abbruch/Persistenz sowie Japan/Deutschland.
