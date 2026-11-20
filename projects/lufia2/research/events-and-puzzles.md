# Ereignisse, Quests und Rätsel: technische Grundlagen

## Geltungsbereich

Dieses Dokument beschreibt die allgemeine Ereignis- und Skriptmaschine der US-Referenz. Es ist **noch kein vollständiger Katalog konkreter Quests oder Rätsellösungen**. Dafür müssen die zugehörigen Karten-, Szenen- und Bedingungsdaten einzeln mit den Skripten verknüpft werden. Keine hier nicht belegte Rätselregel wird ergänzt.

Referenzstand: `Lufia2Decomp` `cc7e14744606e2e3b642101e8d440d8cc0e1f98f`. Die genannten Funktionen sind im Referenzindex als `verified` ausgewiesen. Das bedeutet, dass die Maintainer sie differenziell gegen die unterstützte US-ROM geprüft haben; dieser Auftrag hat diese Differentialprüfung nicht wiederholt. Die ROM wurde in diesem Schritt nicht gestartet.

## Ablauf vom Kartenladen zum Ereignis

### Kartenskripte initialisieren

Beim Laden des Kartenkopfs ruft der rekonstruierte Ablauf `$80:E844` `Lufia2FieldInitializeMapEvents` auf (`src/field/field_map_load.c`, über `Lufia2FieldLoadMapHeader`). Die Routine:

1. löscht 32 Ereignis-Aufzeichnungsfelder zu je vier Bytes ab `$7F:D466`;
2. liest einen Kartenparameter anhand der Karten-ID aus der Tabelle ab `$91:B8B5` und legt ihn bei `$7F:D1A0` ab;
3. liest den Skriptoffset und die Bank aus den Tabellen ab `$8F:8000` und `$8F:8002`;
4. wandelt einen normalen Eintrag in einen 24-Bit-Zeiger im LoROM-Fenster ab `$8000` um. Der Sentinel `$FFFF` setzt die Basis auf null.

Dies ist eine **Karten-Skriptinitialisierung**, keine nachgewiesene Komplettlöschung sämtlicher Ereigniszustände.

Die ROM-Tabelle wurde stichprobenartig mit der bereitgestellten USA-Fassung abgeglichen: Kartenindex 5 hat den Offset `$02D6`, Bankzusatz `$00` und wird daher als Skriptbasis `$8F:82D6` geladen. Die Tabelle bestätigt die 3-Byte-Eintragsform; Karten mit `$FFFF` haben keinen Karten-Eventblock. Der konkrete Ortsname von Kartenindex 5 ist hier absichtlich noch nicht zugeordnet.

Die Header-Zeigertabelle ab `$CF:FCBC` wurde als unabhängige Kartenindex-Grenze herangezogen: Die USA-ROM hat dort 242 zusammenhängende gültige Kartenköpfe (IDs 0–241); ID 242 ist kein gültiger Kartenkopf. Für genau diesen Indexbereich ergibt die lokale Tabellenprüfung 146 Karten mit einem Eventblock und 825 Schlüssel-/Skriptverweisen in den Ereignislisten. Die Listenenden und Zeiger waren bei dieser Prüfung strukturell gültig. Das ist ein Bestandszähler, keine Questzuordnung; die Einzellisten und Originaldaten werden nicht ins Projekt kopiert.

### Auslöser finden

- `Lufia2FieldStartEventAtProbe` (`$80:E7FA`) sucht zunächst einen Punkt-Eintrag. Für diesen Aufruf setzt es den Datensatzabstand auf 3 Bytes. `Lufia2FieldFindPointRecord` (`$83:B851`) durchläuft die Liste ab `$7E:F000` bis zum Endmarker `$FF`; X und Y müssen exakt übereinstimmen. Die Suchroutine nimmt den Abstand vom Aufrufer entgegen, daher gilt der Abstand 3 nicht automatisch für andere Suchlisten.
- Bei einem Treffer wird `Lufia2FieldStartEvent` (`$80:E722`) aufgerufen.
- Der Ereignisstart sucht einen passenden Eintrag in der Karten-Ereignisliste und verwendet einen nicht belegten Skriptplatz. Er setzt dessen Timerbyte auf `$81` (armed-Bit plus Zähler 1), speichert Skriptposition und -bank sowie weitere platzbezogene Werte. Ist kein unbelegter Platz vorhanden, fällt der beobachtete Suchpfad auf Platz 0 zurück; das Überschreiben eines laufenden Ereignisses muss noch mit konkreten Szenarien geprüft werden.
- Eine getrennte Suchroutine, `Lufia2FieldFindRectangleRecord` (`$83:B882`), prüft X und Y innerhalb der beiden Grenzen einschließlich Rand. Der konkrete Zusammenhang dieser Rechteckdatensätze mit einzelnen Rätselarten ist noch nicht vollständig kartiert.
- Szenen-/Dialogsteuerung ist ein eigener Pfad: `Lufia2SceneScriptFindRecord` (`$80:C12E`) sucht einen angeforderten Kennwert in einer terminatorbeendeten Liste und liest den zugehörigen relativen Skriptoffset. Dialogdatensätze und Kartenereignisse dürfen daher nicht ungeprüft als dasselbe Datenformat behandelt werden.

### Aufrufer-Kontext und Trigger-ID auseinanderhalten

Für die spätere Zuordnung von Kartenrätseln müssen zwei verschiedene Werte protokolliert werden: `X` wählt den Listenbereich im Karten-Eventblock; `Y / 2` wählt innerhalb dieser Liste den Skripteintrag. Bei Punktabfragen kommt die Trigger-ID aus Byte 0 des gefundenen Punktdatensatzes. `Lufia2FieldStartEventAtProbe` setzt für die Punktsuche vorübergehend den Datensatzabstand auf 3, stellt danach das ursprüngliche `Y` wieder her und verwendet genau dieses als Eventlisten-Kontext. Das ursprüngliche `X` ist dabei der Startbereich der Punktdatensatzsuche, nicht der Eventlisten-Kontext.

Belegte feste Startwerte aus den Aufrufern:

| Aufruferpfad | Werte vor der Eventsuche | Effektiver Listen-Kontext | Schlüssel bzw. Herkunft |
|---|---|---:|---|
| Kartenkopf laden (`field_map_load.c`, nach `$80:E844`) | `X=0`, `Y=$1E` | `$00` | `$0F` (Kartenstart-Ereignis) |
| Objekt-/Akteurereignis am Spieler (`Lufia2FieldStartObjectEvent`, `field_object_graphics.c`) | `X=0`, `Y=$1C` | `$00` | `$0E` |
| Objekt-VM-Anführerereignis (`ObjectStartLeaderEvent` → `ObjectStartHeaderEvent`, `actor/object_vm.c`) | `X=0`, `Y=$12` | `$00` | `$09` |
| Anführerinteraktion mit Akteur (`field_leader_interaction.c`) | Akteurdatensatz wird gefunden; danach `X=2`, `Y=2×Datensatzbyte0` | `$02` | Byte 0 des gefundenen Akteurdatensatzes |
| Zellaktion vor der Punktabfrage (`Lufia2FieldHandleFacingCellAction`) | zuerst `X=0`, `Y=$0E`; danach Punktabfrage mit Listenstart `$1C`, ursprünglichem `Y=4` | `$00`, danach `$04` | zuerst `$07`, danach Byte 0 des gefundenen Punktdatensatzes |
| Objektinteraktion vor dem Spieler (`Lufia2ObjectStartInteractionEvent`) | Punktabfrage ab `$2A`, ursprüngliches `Y=$0E` | `$0E` | Byte 0 des gefundenen Punktdatensatzes |
| Objekt-/Kollisionsabfrage (`ObjectWakeOrApplyRecord`, `object_vm.c`) | Punktabfrage ab `$1A`, ursprüngliches `Y=8` | `$08` | Byte 0 des gefundenen Punktdatensatzes; anschließend ein separater Header-Eintrag mit `X=0`, `Y=$10` |
| Benutzter Gegenstand (`field_selected_item_action.c`) | `X=0`, `Y=$16` | `$00` | `$0B` |
| Rückkehr aus dem Kampf (`field_battle_transition.c`) | `X=0`, `Y=$0C` | `$00` | `$06` |
| Aktions-/Schrittprobe (`field_probe_dispatchers.c`) | `X=0`, `Y=2` | `$00` | `$01` |
| Ausstehendes Feldereignis (`field_triggers.c`) | `X=0`, `Y=0` | `$00` | `$00` |

Diese Tabelle beschreibt Aufrufverträge, noch keine Spielbedeutung der jeweiligen IDs. Für variable Skript- und Szenenaufrufe müssen die übergebenen Registerwerte weiter bis zu ihrem Ursprung verfolgt werden.

### VM, Ausführung und Warten

- Der Opcode-Index adressiert eine Dispatch-Tabelle ab `$80:E5A4`. Der Event-Interpreter läuft über `Lufia2FieldRunEventScript` (`$80:CC35`).
- Ein unbedingter Sprung addiert einen 16-Bit-Operand zur Skriptbasis. `Lufia2EventNextByte` (`$80:E8B9`) bewegt den Zeiger über LoROM-Bankfenster weiter; `Lufia2EventNextWord` liest Operanden Little-Endian.
- `Lufia2EventFindList` (`$80:E78D`) zeigt die Verweisform der Kartenevents: Für den vom Aufrufer gelieferten Kontext `X` wird ab `Skriptbasis + X` ein relativer 16-Bit-Zeiger zur Liste gelesen. Die Liste besteht aus Einträgen `{Schlüsselbyte, relativer 16-Bit-Skriptzeiger}` und endet mit Schlüssel `$FF`. Der Vergleichsschlüssel kommt aus `Y / 2`; beim Treffer wird der relative Zeiger zur Skriptbasis addiert und als Start des Eventskripts gespeichert.
- Damit sind Kontext und Schlüssel getrennte Größen. Der Aufrufpfad der Objektinteraktion übergibt beispielsweise den Kontext über `$83:EC4F` und die gefundene Trigger-ID als Schlüssel; Positionsereignisse und andere Startpfade verwenden eigene Kontext-/Schlüsselwerte. Eine Questkarte muss beide Werte aus ihrem konkreten Aufrufer und ihrem ROM-Datensatz zusammenführen.
- Es gibt acht Timer-/Skriptplätze bei `$7F:D18C`. Bit 7 kennzeichnet einen aktiven Timer, die unteren sieben Bits zählen Frames. Beim Timerlauf wird ein aktiver Zähler dekrementiert; erreicht er 0, wird der gespeicherte Skriptzeiger wieder aufgenommen. Ein aktives `$80` wird zu `$7F` und dadurch entwaffnet.
- Der Interpreter hat eine Arbeitsgrenze von 4096 Zähleinheiten pro Lauf. Schwerere Bild-/Objekt-/Scrolloperationen belasten dieses Budget mit zusätzlichen 63 oder 255 Einheiten. Das ist keine allgemeine Zusage, dass 4096 gleichartige Befehle in jedem Tick ausgeführt werden.
- Aufrufe und Rückgaben speichern den Zustand der lokalen Variablen und Bedingungen in Ereignis-Aufzeichnungsfeldern. Die genaue Interaktion mit verschachtelten Slots und Grenzfällen wird separat weiter geprüft.

## Zustand, mit dem Quests und Rätsel gebaut werden

| Zustandsart | Belegter Mechanismus | Bedeutung für die weitere Analyse |
|---|---|---|
| Skriptflags | `$7F:D100`, bitweise adressiert; Befehle zum Setzen/Löschen und Verzweigen bei Flag/kein Flag | Kandidat für Story-Fortschritt, dauerhafte Schalter und einmalige Questabschnitte. Welche Nummer wofür steht, muss pro Skript ermittelt werden. |
| Globale Ereignisvariablen | `$7F:D074`; Operanden `$A0–$BF` greifen auf Ereignisvariablen zu | Zähler und temporärer Questzustand sind möglich; Zuordnung und Lebensdauer sind offen. |
| Slotvariablen | `$7F:D15C`, vier Variablen pro Slot, Speicherstride 8 zwischen Variablen | Argumente und lokale Skriptwerte. Operanden `$FB–$FF` besitzen kontextabhängige Sonderbehandlung. |
| Ergebnis einer Bedingung | `$7F:D19A`; Bit 7 wird als wahr/falsch gespeichert, zusätzlich ist ein Operandbyte vorhanden | Bedingungen können ein Ergebnis merken, verzweigen oder es mit einem Flag vergleichen. |
| Skriptplätze und Zeiger | Timer `$7F:D18C`, Zeiger `$7F:D134`, Bits `$7F:D14C`, Slotpositionen `$7F:D17C/$7F:D184` | Mehrere Ereignisse können zeitlich überlappend laufen; Folgen und Konkurrenz müssen je Karte geprüft werden. |
| Räumpunkte/Koordinaten | 64 X-/Y-Punkte und Kopien bei `$7F:D1A3`, `$7F:D1E3`, `$7F:D223`, `$7F:D263` | Rätsel können mit benannten Punkten statt fest kodierter einzelner Koordinate arbeiten. Der konkrete Operandvertrag ist noch nicht vollständig beschrieben. |
| Kartenobjekte und Zellen | Befehle prüfen/verändern Objektbits, Kartenobjekte, Zellwerte, Zellenmasken und Positionen | Deckt technische Bausteine für Schalter, verschiebbare Blöcke, Türen und Bodenbedingungen ab; konkrete Rätsel müssen über die jeweiligen Kartendaten zugeordnet werden. |

## Bereits nachgewiesene Bedingungs- und Aktionsfamilien

Der Opcode-Index und die Handler in `field_event_conditions.c`, `field_event_objects.c`, `field_event_actors.c` und `field_event_script.c` belegen folgende Familien:

- Sprünge nach Skriptflag sowie Setzen und Löschen von Skriptflags.
- Vergleiche von Variablen mit anderen Variablen oder Konstanten: gleich, ungleich, größer, kleiner, mindestens und höchstens.
- Tests auf Spielerposition und Zellen-/Tilebits, unter anderem Masken `$09`, `$08` und `$01`; außerdem Vergleich von Positionen und Tilegleichheit.
- Bedingungen über Objekte bzw. Objektzustände in einem Bereich und über den Spieler in einem Bereich.
- Reaktion auf Spieleraktionen/Richtung und auf beanspruchte Interaktionsobjekte.
- Objektzustand setzen/löschen, Objekt an einer Position platzieren und Kartenobjekte in vier Richtungen verschieben.
- Figuren setzen, bewegen, verstecken/anzeigen und auf deren Bewegung warten.
- Warten, parallele Skripte starten, Skriptunterprogramme aufrufen/zurückkehren, Kamera/Layer bewegen und Kartenübergänge auslösen.

Das Vorhandensein eines Opcodes beweist noch nicht, dass ein bestimmter Ort ihn verwendet. Ebenso ist die vollständige Kombinationslogik jeder Bedingungsliste noch offen. Die konkrete Rätselregel entsteht erst aus Auslöser + Bedingung + Folge im jeweiligen Kartenskript.

### Dialog-/Textskripte als zweite Questebene

Questfortschritt läuft nicht nur über die Feld-Event-VM. Die Textmaschine hat eigene bedingte Befehle und Partyaktionen:

- Textopcode `$15` springt, wenn ein Eventflag gesetzt ist; `$1A/$1B` setzen bzw. löschen Eventflags. `Lufia2TextTestFlag` (`src/text/text_engine.c`, `$80:BE1E`) liest dafür den allgemeinen Eventflagbereich ab `$7E:077E`.
- Das ist ein anderer Speicherbereich als die vier Eventskriptflags bei `$7F:D100–$7F:D103`, die im Feldskript-VM-Dokument beschrieben werden. Beim Questmapping müssen beide Nummernräume getrennt erfasst und ihre Übertragungen verfolgt werden.
- Textbedingungen und Partybefehle können Itembesitz/-anzahl, Gold und Kartenobjektbits prüfen. Befehle können Gegenstände entfernen, Zauber lernen, Gruppenmitglieder hinzufügen/entfernen, Werte verändern, Figuren sichtbar machen oder die Party heilen.
- Beispielquellen: `src/text/text_conditions.c` (`$80:A074`-Bedingungsauswertung), `src/text/text_party_commands.c` (`$80:A29C` Flagbranch, `$80:AC28` Itemanzahl, `$80:AE03` Gold, `$80:AE24` Objektbit, `$80:A4DD` Partyrestore) und der Text-Opcode-Dispatcher in `src/text/text_engine.c`.

Diese Ebene ist für Quests besonders wichtig: Eine Dialogauswahl oder ein Textkommando kann eine Questflag setzen, eine Waren-/Gegenstandsbedingung testen oder die Gruppe ändern, ohne dass derselbe Vorgang als Feld-Eventopcode erscheint. Konkrete Questketten benötigen deshalb die Verknüpfung von Kartenrecord, Szenenrecord, Textscript, beiden Flagbereichen und Spielbeobachtung.

## Was die bisher untersuchten Reset-Routinen wirklich tun

### Reset der Karten-Ereignisdaten

`Lufia2FieldResetMapEventState` (`$83:B581`, `src/field/field_event_reset.c`) bewirkt direkt:

- Nullen von 48 Bytes ab `$7F:D104`;
- Nullen aller 64 Einträge in den vier X-/Y-Punktarrays;
- zusätzliches Nullen von 32 Bytes ab `$7F:D114`, einem Bereich, der bereits in `$D104–$D133` liegt.

In dieser Routine wird `$7F:D100` (der Skriptflagbereich), der Timerbereich und das Skriptbasisfeld nicht direkt genullt. Der Aufrufer ist `Lufia2FieldInstallMap` (`$83:B53B`, `src/field/field_map_install.c`): Im Zweig, in dem Bit 0 von `WRAM_FIELD_MAP_FLAGS` gelöscht ist, wird `$83:B581` nach dem Laden/Installieren der Kartenattribute aufgerufen. Die genaue Bedeutung dieses Map-Flags und ob alle Arten von Szenen-/Kartenwechsel denselben Zweig durchlaufen, bleibt offen. Aus diesem selektiven Reset darf nicht gefolgert werden, dass ein Kartenwechsel alle Eventflags, Timer oder Objektzustände löscht.

### Andere Resets, noch offen

Für eine vollständige Rücksetzmatrix müssen Start eines neuen Spiels, Fortsetzen/Laden, Karten-/Szenenwechsel, Rast und Heilung, Kampfstart/-ende, Niederlage, Speicherpunkt, Ahnenhöhlen-Eintritt, Höhlen-Niederlage und Höhlen-Verlassen jeweils verfolgt werden. Die Ahnenhöhle besitzt bereits dokumentierte eigene Reset-/Eintrittsroutinen; sie sind kein Beleg für das Verhalten der restlichen Spielwelt.

## Nächste Arbeit an konkreten Quests und Rätseln

Für jede Karte und Questkette wird ein eigenes Ablaufblatt erstellt mit:

1. Ort, Kartennummer und auslösender Datensatz;
2. benötigten Questflags, Gegenständen, Gruppenmitgliedern oder vorherigen Ereignissen;
3. exakten Koordinaten-/Objekt-/Zellenbedingungen und ihrer Verknüpfung;
4. Branches für Erfolg, Fehlschlag, Wiederholung und bereits erledigten Zustand;
5. Änderungen an Flags, Variablen, Objekten, Gruppe, Inventar, Karte oder Folgeereignis;
6. Spielbeobachtung und Regionsvergleich, soweit verfügbar.

Die konkrete US-ROM wurde zuvor identifiziert und der Ressourcen-Decoder bestand den Vollständigkeitscheck. In diesem Analyseschritt wurden die Kartenkopf- und Eventzeigertabellen direkt gelesen und der Ereignislistenbestand gezählt; es wurden keine Skriptinhalte als Ressourcendump abgelegt und keine Originalspielabläufe beobachtet. Eine interaktive Emulatorlaufzeit stand nicht zur Verfügung: Es gab kein Emulatorbinary in der Shell und die Desktopinitialisierung scheiterte. Deshalb stehen noch keine einzelnen Questlösungen als bestätigt im Dokument. Japan- und Deutschlandabweichungen bleiben ebenfalls offen.

## Quellen im Referenzcheckout

| Adresse | Funktion/Datei | Belegstatus und verwendeter Befund |
|---|---|---|
| `$80:E844` | `Lufia2FieldInitializeMapEvents`, `src/field/field_map_load.c` | Metadaten `verified`; Kartenparameter, Skriptbasis und Aufzeichnungsfelder. |
| `$CF:FCBC` | Kartenkopf-Zeigertabelle, `src/field/field_map_load.c` (`MapHeaderSource`) | USA-ROM-Indexbereich 0–241 gegen bereitgestellte ROM geprüft; ID 242 ist kein gültiger Header. |
| `$80:E722` | `Lufia2FieldStartEvent`, `src/field/field_event_script.c` | Metadaten `verified`; Slotbelegung und Zeigerspeicherung. |
| `$80:E7FA` | `Lufia2FieldStartEventAtProbe`, `src/field/field_event_triggers.c` | Metadaten `verified`; Punktabfrage und Ereignisstart. |
| `$83:B851`, `$83:B882` | `field_record_search.c` | Metadaten `verified`; Punkt- und Rechtecksuche. |
| `$80:CC35`, `$80:CBAE`, `$80:E78D` | Event-VM, Timertick und Ereignislistenauflösung, `src/field/field_event_script.c`, `field_event_conditions.c` | VM-Aufbau, 4096er Budget mit Kostenaufschlägen, Timerverhalten und Kontext-/Schlüssel-/Zeigerform nachvollzogen. |
| `$80:C12E` | `Lufia2SceneScriptFindRecord`, `src/field/field_scene_records.c` | Metadaten `verified`; Szenenrecord-Auswahl. |
| `$80:E3D9–$80:E400`, `$80:E132`, `$80:CD81` u. a. | Bedingungen, `src/field/field_event_conditions.c` | Bedingungsfamilien aus Handlern und gemeinsamen Ergebnisroutinen abgeleitet. |
| `$80:BE1E`, `$80:A074`, `$80:A29C`, `$80:AC28`, `$80:AE03`, `$80:AE24` | Text-/Partybedingungen, `src/text/text_engine.c`, `text_conditions.c`, `text_party_commands.c` | Allgemeine Eventflags, item-/gold-/objektbasierte Checks und Party-/Questaktionen. |
| `$83:B53B` → `$83:B581` | `Lufia2FieldInstallMap` → `Lufia2FieldResetMapEventState`, `src/field/field_map_install.c` / `field_event_reset.c` | Metadaten `verified`; Karteninstallierer ruft den selektiven Event-Reset im Zweig mit gelöschtem Map-Flagbit 0 auf. |
