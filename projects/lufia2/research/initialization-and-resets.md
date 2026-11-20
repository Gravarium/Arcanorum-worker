# Initialisierung, Reset, Heilung und Wiederherstellung

## Zweck und Beleggrenze

Diese Datei sammelt Rücksetz- und Wiederaufnahmewege quer durch das Spiel. **Reset** bedeutet hier nicht eine einzelne Funktion: Kaltstart, Standarddatensatz, Event-Neustart, Heilung, Laden und Ahnenhöhlen-Reset schreiben jeweils andere Zustandsbereiche.

Referenzstand: `Lufia2Decomp` `cc7e14744606e2e3b642101e8d440d8cc0e1f98f`. Die unten genannten öffentlichen Funktionen tragen im Index den Status `verified`; die private ROM-Differentialprüfung wurde in diesem Auftrag nicht wiederholt. Der Ablauf wurde aus C rekonstruiert. Für die konkreten Aufrufpfade aus Spielzuständen und für sichtbare Originalspiel-Ergebnisse bleibt die Matrix teilweise offen.

## Vorläufige Reset-Matrix

| Vorgang | Nachgewiesener Einstieg und Wirkung | Grenzen / offene Fragen |
|---|---|---|
| Hardware-/Kaltstart | `Lufia2ResetSystem` (`$80:8000`, `src/system/boot.c`) sperrt Interrupts, setzt CPU-Modus/Stack/Direct Page und schaltet Video-/DMA-Steuerung ab. `Lufia2RunBootSequence` (`$80:8035`) initialisiert danach Video, Laufzeitdefaults, Save-Prüfung, Sound und RNG und geht anschließend in die Titelauswahl bzw. Feldinitialisierung. | Exakte Varianten je Resetursache und welche SRAM-Werte unverändert bleiben, müssen noch mit vollständigem Einstiegspfad geprüft werden. |
| Start-Work-RAM leeren | `Lufia2ClearStartupWorkRam` (`$80:80D3`) schreibt Nullwörter ab Direct-Page-Adresse 0 bis zum im Code gesetzten Grenzwert `$1DFE`. Der Bootpfad ruft es im Zweig mit passendem Sound-Signaturwert auf. | Nicht mit einem Löschen von SRAM oder einem neuen Spiel verwechseln. Der andere Signaturzweig hat einen anderen Ablauf. |
| Laufzeitdefaults | `Lufia2InitializeRuntimeDefaults` (`$80:80E1`) setzt Call-Templates, Fade-/Helligkeits- und Initialanforderungszustand und einige feste Laufzeitwerte. | Kein Beleg für Story-/Party-Defaults. |
| Feldszene initialisieren/zurücksetzen | `Lufia2FieldResetScene` (`$83:ADCA`, `src/field/field_scene_reset.c`) wird vom Session-Setup aufgerufen. Es führt der Reihe nach `Lufia2StartDefaultRecords` (`$81:EC35`), `Lufia2MenuResetSessionRecords` (`$82:9AA3`), `Lufia2FieldResetSavedScene` (`$83:ADDF`) und `Lufia2ActorResetSceneSlots` (`$83:A686`) aus. | Das ist ein konkreter Mehrsystem-Resetpfad. Welche Aufrufart/Übergangsursache diese Scene-Reset-Variante auswählt, wird noch bis zu den Titelauswahl- und Fortsetzenpfaden verfolgt. |
| Standard-Spielerdaten | `Lufia2InitializeDefaultRecords` (`$81:EC56`, `src/system/default_records.c`) leert den beschriebenen WRAM-Recordpufferbereich über die Memory-Data-Port-Routine, richtet Party-/Modifier-/Item-Zeiger ein, kopiert Party-IDs und Standardinventarwerte und lädt sieben Standardcharakterdatensätze in die Arbeitsrecords. Es wird über `Lufia2StartDefaultRecords` durch den Feldszene-Reset aufgerufen. | Die konkrete Funktion ist geprüft; fachliche Zuordnung jedes Standardwerts und ihr Verhältnis zu Neu-Spiel-/Continue-Auswahl sind offen. |
| Standardparty neu anwenden | `Lufia2PartyResetDefaultRecords` (`$81:ED35`) liest sieben Standardcharakterdatensätze erneut in die Partyrecords und ruft zwei abgeleitete Party-/Stat-Serviceziele auf. | Das ist eine Standardparty-Routine, nicht automatisch die einzige Resetregel des ganzen Spiels. Der im Höhlenreset folgende Service `$82:C2FD` ist gesondert zuzuordnen. |
| Sessiondatensätze zurücksetzen | `Lufia2MenuResetSessionRecords` (`$82:9AA3`) löscht Spielzeitfelder, setzt Auswahl-/Restorezustand und ruft anschließend `Lufia2CapsuleInitializeSavedRecords` (`$82:C2AE`) auf, das Kapsel-Zwischenwerte löscht und Anfangswerte lädt. | Teil des Feldszene-Resetpfades, aber ob er auch bei jeder Rückkehr aus Continue oder Kartenübergang läuft, bleibt offen. |
| Gespeicherte Szene und Actorzustand | `Lufia2FieldResetSavedScene` (`$83:ADDF`) stellt zuerst den Feldpuffer wieder her, liest gespeicherte Actor-IDs/-zustände, leert mehrere Event-/Szenen-/Partyarbeitsfelder und setzt Optionen/Steuerungszustand. `Lufia2ActorResetSceneSlots` (`$83:A686`) setzt danach 40 Actorplätze, Runtimefelder und beanspruchte Actor-IDs zurück. | Darunter liegt der separat benannte allgemeine Eventflagbereich `$7E:077E–$7E:079D` sowie Scene-Map-Flags; diese sind von den vier Eventskriptflags `$7F:D100–$7F:D103` verschieden. Fachliche Bedeutung und wann der Savebuffer danach wieder eingespielt wird, werden anhand konkreter Abläufe weiter zugeordnet. |
| Temporäre Feldsicherung | `Lufia2CaptureFieldSaveState` (`$8E:BA0D`) kopiert Karten-ID, Layer, Feldflags, Actorrecords, Objektzustandsbits, Szenenbytes, Skriptflags, drei weitere Feldzustandsbytes und Hintergrundwert in einen Arbeitsbuffer. `Lufia2RestoreSavedFieldState` (`$8E:B993`) kopiert diese Werte zurück. | Diese Sicherung ist eine Laufzeitkopie. Sie ist nicht gleich dem Packen eines Batteriespeicherstands. Welche Menü-/Szenenaufrufe sie benutzen, muss vollständig kartiert werden. |
| Batterie-Speicherstand packen/laden | `Lufia2SavePackState` (`$85:C954`) packt Feldzustand, Partyrecords, Kapselwerte und Battle-Untermenüauswahl in den Save-Record und berechnet dessen Prüfsumme. `Lufia2SaveRestoreState` (`$85:C60E`) stellt die Daten nach Prüfsummenprüfung wieder her. `Lufia2SaveGameFile`/`Lufia2LoadGameFile` (`$80:90C9`/`$80:9099`) koordinieren die Dateioperationen; Laden ruft Field-State-Restore und Save-State-Restore auf, wenn der Recordmarker passt. | Darstellung/Fehlerdialog, genaue Slotwechsel-Folgen und alle Sonderfälle fehlerhafter/älterer Records werden im Speicherkapitel ergänzt. |
| Volle Heilung im Party-Textbefehl | Der Textbefehl `RestoreParty` (`src/text/text_party_commands.c`, `$80:A4DD`) ruft der Reihe nach `PartyClearStatuses` (`$81:F629`), `PartyRestore11` (`$81:F5ED`) und `PartyRestore13` (`$81:F60B`) auf. Die letzten beiden kopieren Max-HP nach Current-HP bzw. Max-MP nach Current-MP für aktive Partymitglieder. | Das belegt die Wirkung dieses Skriptbefehls, aber nicht, dass jede Herberge oder jeder Rastpunkt genau diesen Befehl nutzt. Die einzelnen Restore-Routinen überspringen Datensätze mit Statusbit `$04`; der zusammengefasste Textbefehl löscht die Statusbytes vor dem Wiederherstellen. |
| Feld-Recovery-Objekte | `Lufia2FieldStartHpRecovery` (`$83:813B`) und `Lufia2FieldStartMpRecovery` (`$83:816D`) starten getrennte Recovery-Animationen/Requests; ihre Tick-Routinen warten die Requests ab. Dabei werden die Party-HP- bzw. MP-Restorepfade ausgelöst. | Noch offen ist, welche Kartenobjekt-ID und welche konkreten Orte zu HP, MP oder voller Heilung führen. Die Daten müssen pro Ort aus Karten-/Szenenrecords gelesen werden. |
| Ahnenhöhle betreten | `Lufia2AncientCaveEnter` (`$84:878A`) erhöht den Eintrittszähler mit Sättigung, setzt mehrere Höhlenzustandsbytes zurück, sammelt erlaubte blaue Gegenstände aus Inventar/Ausrüstung, sichert mehrere WRAM-Ranges als Backup und geht in den Höhlenpfad. | Vollständige Bedeutung aller Backup-Ranges und Persistenz außerhalb der Höhle bleibt Teil der offenen Höhlenanalyse. |
| Ahnenhöhlenparty zurücksetzen | `Lufia2AncientCaveResetParty` (`$84:8888`) leert die über eine terminierte Range-Tabelle beschriebenen Partybereiche, setzt `$0A8D` auf `$1402`, fügt gespeicherte Startitems nur bei Inventarmodus 0 wieder hinzu, wendet Standardpartyrecords an und ruft danach einen weiteren Partyservice auf. | Exakte Feldbedeutung der Range-Tabelle und des Zusatzservice noch nicht vollständig erschlossen. |
| Ahnenhöhlen-Niederlage | `Lufia2AncientCaveDefeat` (`$84:8B9C`) setzt Inventarmodus auf `$FF`, löst Partyreset aus, zählt Niederlagen und setzt Feld-/Reloadflags für die Rückkehr. | Ob Exit, Niederlage und manuelles Verlassen dieselben gespeicherten Ausrüstungs-/Carrywerte behandeln, wird noch verglichen. |

### Reihenfolge im Feldszene-Reset

Der Session-Setup-Code ruft `$83:ADCA` in mehr als einem Setupzweig auf. Der Reset selbst ist ein Orchestrator mit vier aufeinanderfolgenden Schritten:

1. `$81:EC35` (`Lufia2StartDefaultRecords`) initialisiert Standarddatensätze und Partyrecords.
2. `$82:9AA3` (`Lufia2MenuResetSessionRecords`) setzt unter anderem Spielzeit und Session-/Auswahlzustand zurück und initialisiert gespeicherte Kapsel-Zwischenrecords neu.
3. `$83:ADDF` (`Lufia2FieldResetSavedScene`) ruft zuerst `$83:B062` zum Wiederherstellen der Feldpuffer auf, liest danach eine Tabelle gespeicherter Actordaten aus Bank `$87`, leert die in `SceneClearActorState` aufgeführten Ereignis-/Szenen-/Partyarbeitsbereiche (einschließlich `$7E:077E–$7E:079D` und Scene-Map-Flags) und setzt Optionen-/Steuerungsbytes.
4. `$83:A686` (`Lufia2ActorResetSceneSlots`) durchläuft 40 Actorplätze, setzt Status- und Laufzeitfelder zurück und löscht beanspruchte Actor-IDs.

Damit sind die direkten Arbeitsschritte bekannt, aber die fachliche Bedeutung ist noch nicht gleichbedeutend mit „neues Spiel“ oder „Storyfortschritt löschen“. Der tatsächliche Menü-/Titeldispatch muss bestätigen, wann `$83:ADCA` erreicht wird und ob andere Eintrittsarten eine kürzere Wiederaufnahme statt dieses Resets verwenden.

## Save-Daten, die für Questfortschritt wichtig sind

Die Feldsicherung packt nicht bloß Position und Karten-ID. Sie übernimmt auch:

- einen allgemeinen Event-/Fortschrittsflagblock;
- neun Bytes Kartenobjektzustand;
- neun Bytes Szenenzustand;
- vier Bytes Skriptflags aus `$7F:D100`;
- Partyrecords und weitere Speicherdaten werden im übergeordneten Recordpacker behandelt.

Das ist ein direkter Beleg dafür, dass Flag- und Objektzustand Speicherrelevanz haben. Es sagt für sich allein noch nicht, welche konkrete Questflagnummer erhalten bleibt oder wann ein neues Spiel diese Bereiche initialisiert.

## Wichtige Trennung der Lebensdauern

1. **Hardware-/Laufzeitstart:** CPU, RAM-Workbereich, Videoregister, Sound/RNG und initiale Laufzeitfelder.
2. **Standarddatensatz/Neues Spiel:** Partei-, Item- und Golddefaults in Spielrecords.
3. **Karten-/Szenenreset:** einzelne map-/eventbezogene Arrays, Slotpointer, Objekt- und Skriptflags mit unterschiedlichen Lebensdauern.
4. **Erholung:** aktuelle HP/MP und Statuswerte; nicht gleichbedeutend mit Questreset.
5. **Speicherstand:** ausgewählte, gepackte Fortschritts- und Feldzustände, die nach Prüfung geladen werden.
6. **Ahnenhöhlenreset:** getrennte Backup-, Carry- und Partylogik mit Rückkehrpfad.

Diese Einteilung ist ein Recherchemodell. Die exakten Übergänge zwischen den Ebenen werden erst dann als vollständig markiert, wenn ihre Aufrufer im Titel-, Menü-, Feld- und Kampfablauf verknüpft sind.

## Noch zu schließen

- Kaltstart/Softreset bis zur Titelauswahl und der exakte neue-Spiel-vs.-Fortsetzen-Branch.
- Menüpfad, der `InitializeDefaultRecords` und `PartyResetDefaultRecords` auslöst.
- Vollständige Save-Slot-Lade-/Speicherfehlerfälle und Checksummenpfad.
- Kartenwechsel: welche Bereiche in jedem Übergangszweig neu geladen, gesichert, wiederhergestellt oder gelöscht werden; Bedeutung von Map-Flagbit 0.
- Rast/Herberge: Kartenobjekt-/Szenenrecord und verwendeter Recoverybefehl pro Ort.
- Kampfende, Niederlage/Game Over und Wiederbelebung außerhalb der Ahnenhöhle.
- Alle Quest-/Scriptflags: welche beim Neuanfang genullt, beim Laden wiederhergestellt oder beim Kartenwechsel erhalten werden.
- Japanische und deutsche Varianten, danach beobachtete Spielzustände für die USA-Fassung.

## Quellen und Status

Referenzfunktionen in `metadata/functions.toml` sind für diese Einträge mit `verified` ausgewiesen: `Lufia2ResetSystem`, `Lufia2RunBootSequence`, `Lufia2ClearStartupWorkRam`, `Lufia2InitializeRuntimeDefaults`, `Lufia2InitializeDefaultRecords`, `Lufia2StartDefaultRecords`, `Lufia2PartyResetDefaultRecords`, `Lufia2FieldResetScene`, `Lufia2FieldResetSavedScene`, `Lufia2MenuResetSessionRecords`, `Lufia2ActorResetSceneSlots`, `Lufia2FieldInstallMap`, `Lufia2FieldResetMapEventState`, `Lufia2CaptureFieldSaveState`, `Lufia2RestoreSavedFieldState`, `Lufia2SavePackState`, `Lufia2SaveRestoreState`, `Lufia2LoadGameFile`, `Lufia2SaveGameFile`, `Lufia2FieldStartHpRecovery`, `Lufia2FieldStartMpRecovery`, `Lufia2PartyRestoreVitals`, `Lufia2PartyRestore11`, `Lufia2PartyRestore13`, `Lufia2AncientCaveEnter`, `Lufia2AncientCaveResetParty` und `Lufia2AncientCaveDefeat`.

Die Statusangabe ist keine neue ROM-Ausführung. Es wurden keine neuen Laufzeitfälle oder Tests für diese Resetroutinen ausgeführt.
