# Quellenkarte und Versionsstand

## Geprüfter Quellstand

- Referenzrepository: Lufia2Decomp, öffentlicher Hauptbranch.
- Commit: `cc7e14744606e2e3b642101e8d440d8cc0e1f98f`, datiert auf 2026-11-18. Am 2026-11-20 mit `HEAD` und `main` auf GitHub abgeglichen.
- Referenzumfang: semantische C-Rekonstruktion der US-SNES-Fassung. Das Repository enthält keine ROM-Dateien oder eingebetteten Originaldaten und ist keine bytegenaue Disassemblierung.
- `metadata/functions.toml` wurde mit Python `tomllib` eingelesen. Ergebnis: 2.305 Einträge, davon 2.305 mit `status = verified`.

Laut README des Referenzprojekts bedeutet `verified`, dass eine Funktion die private differenzielle Prüfung gegen die unterstützte Original-ROM bestanden hat. Diese Aussage wurde hier nicht erneut gegen eine ROM ausgeführt. Sie bestätigt außerdem nur den Funktionsstatus, nicht automatisch die Vollständigkeit der folgenden Regelbeschreibung.

## Bereitgestellte ROMs und Prüfungen

| Fassung | Größe | SHA-256 | Prüfung |
|---|---:|---|---|
| USA, `Lufia II - Rise of the Sinistrals (USA).sfc` | 2.621.440 Byte | `7c34ecb16c10f551120ed7b86cfbc947042f479b52ee74bb3c40e92fdd192b3a` | Das ROM-Werkzeug des Referenzprojekts dekodierte 680 von 680 Ressourcen. |
| Japan, `Estpolis Denki II (Japan).sfc` | 2.621.440 Byte | `f62df5ffda3042b7d7717cb49be1029defa0de28191a8c66e380d1c9adbebc6f` | SNES-LoROM erkannt. Das ROM-Werkzeug weist es zurück, weil es nur die US-Fassung unterstützt. |

Der dritte hochgeladene Anhang ist ein DEGAS-Bild und keine Spiel-ROM. Die ROM-Dateien blieben außerhalb des öffentlichen Repositories.

Für den kompilierten RNG-Code wurde ein temporärer Bus-Harness verwendet. Er bestätigte die LoROM-Abbildung der US-Adresse `$83:9F49` auf Datei-Offset `$19F49`. Die Funktionsfälle selbst lasen keine Bytes aus dem ROM. Die getesteten Formeln und Grenzen stehen in [rng.md](rng.md).

## Fundstellen für RNG und Ahnenhöhle

| Bereich | Fundstelle im Commit `cc7e147…` | Befund und Grenze |
|---|---|---|
| RNG-Seed | `src/system/random.c`, `$80:82E7`, `Lufia2SeedRandom`; Metadatenstatus `verified` | Tabelleninitialisierung und Mischläufe sind im C nachvollziehbar. Aufrufer und Herkunft des Seed-Bytes sind in den Decomp-Quellen nicht gefunden. |
| RNG-Refill | `src/system/random.c`, `$80:832D`, `Lufia2RefillRandomTable`; `RandomRefill` | 55 Byte, in-place XOR mit den Abständen 31 und 24. Der gesamte Aufrufstrom des Spiels ist damit nicht abgedeckt. |
| RNG-Byte und Skalierung | `src/system/random.c`, `$80:82C7`, `Lufia2RandomByte`; `$80:8299`, `Lufia2RandomScale` | Beide Einträge sind `verified`. Skalierung ist ein Produkt-Highbyte und kein Zurückweisen oder Modulo. |
| Höhlenzufall | `src/cave/cave_random.c`: `$83:9DE4` `Lufia2CaveDrawRandomMean`, `$83:9E11` `Lufia2CaveDrawRandomIndex`, `$83:9E1B` `Lufia2CaveDrawRandomBelow`; drei Metadateneinträge mit Status `verified` | Wrapped Mean, doppelter Index und Mode-7-Skalierung. Der Höhlengenerator ruft diese Einstiege auf. |
| Etagenaufbau | `src/cave/ancient_cave.c`, `$83:9E31`, `Lufia2AncientCaveGenerateFloor`; Metadatenstatus `verified` | Der vollständige Einstieg wählt den Etagenkontext, ruft Map- und Soundroutinen auf, baut die Etage und erhöht danach den internen Zähler. |
| Generatorphasen | `src/cave/ancient_cave.c`, `$83:9013–$83:99C7`, interne Funktion `Lufia2CaveBuildFloor` | Phasenfolge und zahlreiche Entscheidungen sind aus dem C rekonstruiert. `CaveBuildFloor` hat keinen eigenen Metadateneintrag. |
| Raster und Zellwahl | `src/cave/cave_grid.c`: `$83:9B18` `Lufia2CaveClearVisitedCells`, `$83:9B27` `Lufia2CaveCountOccupiedCells`, `$83:9B62` `Lufia2CaveLinkRoomCells`, `$83:9BDD` `Lufia2CaveMergeRoomCells`, `$83:9CA0` `Lufia2CaveFillRoomRectangle`, `$83:9D11` `Lufia2CavePickUnvisitedCell`, `$83:9D23` `Lufia2CaveFindUnvisitedCell`; alle `verified` | Raster ist 16×16 Zellen. Raum- und Formraster liegen getrennt bei `$7F:EA00` und `$7F:EB00`. |
| Objekte und Truhen | `src/cave/cave_objects.c`: `$83:99C8` `Lufia2CaveResolveTilePosition`, `$83:99D3` `Lufia2CaveProbeObjectPlacement`, `$83:9A1C` `Lufia2CavePlaceRandomObject`, `$83:9A6B` `Lufia2CaveProbeNearbyChest`, `$83:9A9F` `Lufia2CaveProbeChestAtPosition`, `$83:9ABC` `Lufia2CaveTryPlaceRoomChest`; alle `verified` | Platzierungsprüfungen, Kapazitätsgrenzen und Auswahlablauf sind nachvollziehbar. Die ROM-Tabellen mit konkreten Objekt- und Itemwerten sind im Decomp-Repository nicht enthalten. |
| Etagenkopf | `src/cave/cave_header.c` | Schreibt Treppe, Raumzugänge, Raumpunkte und Truhen in den generierten Kartenkopf. Datenformate werden weiter geprüft. |
| Eintritt / Gruppe zurücksetzen | `src/cave/cave_entry.c`, `$84:878A`; `src/cave/cave_reset.c`, `$84:8888`; beide `verified` | Eintritt sichert Spielzustand, setzt Höhlenzustände zurück und führt den Gruppenreset aus. Die fachliche Bedeutung aller kopierten Zustandsbereiche ist nicht vollständig geklärt. |
| Niederlage / Verlassen | `src/cave/cave_reset.c`, `$84:8B9C`; `src/cave/cave_exit.c`, `$84:890B`; beide `verified` | Moduswechsel und Rückgabewege sind nachvollzogen. Die genaue Carry-Auswahl, Verhalten bei vollen Stapeln und alle persistenten Zustände bleiben offen. |
| Erster Etagenaufruf | `src/field/field_session_setup.c`, `LoadSessionMap`, Aufrufstelle `$83:B26D` | Beim Eintritt in das Höhlenkarten-Szenario wird der Zähler bei Null auf 1 gesetzt und danach `$83:9E31` aufgerufen. Der Generator erhöht ihn nach erfolgreichem Aufbau erneut. |

Die Speicheradressen und Generatorparameter stammen aus `src/cave/wram.h` und `src/system/wram.h`. Die konkreten Werte der Tabellen bei `$83:9F49–$83:9F80`, `$91:FFCA`, `$91:FFDC`, `$94:EEA0`, `$94:F13D` und weiteren ROM-Adressen werden durch Lesezugriffe referenziert, sind aber nicht als Tabellendaten im öffentlichen Quellstand enthalten.

## Gegenprüfung der bisherigen Übersicht

Folgende frühere Hinweise sind am geprüften Commit bestätigt:

- Getrennte 16×16-Raster für Raumbelegung und Zellform.
- Generatorphasen von Rasterlöschung über Räume, Verbindungen und Platzierungen bis zum Zeichnen und Kartenkopf.
- Itemlistenfilter nach Feldbenutzbarkeit, zwei Ausschlussbits und Preisgrenze.
- Interne Preisgrenze `1000 × (Etagenzähler + 1)` unterhalb `0x3C` und ab `0x3C` auf `0xFFFF`. Der strikte Preisvergleich lässt auch den Wert `0xFFFF` selbst nicht zu.
- Gesonderte Rollpfade für Story-/Scrolltruhen und normale Truheninhalte.

Die Liste der Phasen und die Kernberechnungen sind damit am US-Referenzcode geprüft. Die drei Höhlenzufallseinstiege sind zusätzlich als eigenständige Funktionen in `metadata/functions.toml` verzeichnet. Kardinalitäten ausgewählter USA-Beutetabellen wurden an der bereitgestellten ROM geprüft. Itemnamen, Objekttabellen, vollständiger RNG-Verbrauch aller Bedingungen und das beobachtete Verhalten im Spiel bleiben offen.

## Nicht abgedeckte Belege

- Die USA-ROM wurde identifiziert und ihre 680 komprimierten Ressourcen wurden dekodiert. Ausgewählte Höhlen-Beutetabellen wurden gelesen, ohne ihre Rohdaten zu diesem Repository hinzuzufügen.
- Die Japan-ROM wurde erkannt, aber nicht gegen US-Adressen ausgewertet. Dafür fehlen eine japanische Quell- und Adresskarte sowie passende Differentialtests. Aussagen zu gemeinsamen Regeln oder Regionsunterschieden bleiben offen.
- Der temporäre Harness führte rekonstruierten C-Code gegen mathematische Referenzen aus. Das Originalspiel und seine ROM-Maschinencodepfade wurden nicht ausgeführt. Es gibt daher weiterhin keine Originalbeobachtung oder ROM-Differenzprüfung dieser Mechaniken.
- Der lokale `ctest`-Aufruf fand im öffentlichen Referenzcheckout keine Tests. Die private ROM-Differenzsuite ist dort nicht enthalten.
