# Lokale PostgreSQL- und Redis-Container

Diese Compose-Umgebung stellt PostgreSQL 18 und Redis 8 ausschließlich für lokale Entwicklung und Tests bereit. Die veröffentlichten Ports sind an `127.0.0.1` gebunden. Datenbankname, Benutzer und Passwort sind ausdrücklich lokale Testwerte und dürfen nicht für echte oder produktive Daten verwendet werden.

## Voraussetzungen

- Docker Engine oder Docker Desktop
- Docker Compose v2 (`docker compose`)

## Start und Bereitschaft

Starte beide Dienste und warte auf ihre Healthchecks:

```bash
docker compose up -d --wait --wait-timeout 120
```

Prüfe den Zustand bei Bedarf erneut:

```bash
docker compose ps
docker compose exec -T postgres psql -U walker_local -d walker_test -tA -c 'SHOW server_version;'
docker compose exec -T redis redis-cli --raw ping
```

PostgreSQL muss eine Version mit Majorversion `18` melden. Redis muss mit `PONG` antworten.

Die vollständige, auch in CI verwendete Prüfung validiert die aufgelöste Compose-Konfiguration zusätzlich auf ausschließlich lokale Portbindungen (`127.0.0.1`), die vorgesehenen Images und Ports sowie fehlende privilegierte Host-Vernetzung. Sie weist auch Overrides mit anderen Zugangswerten, eingebundenen Daten, deklarierten Volumes oder fehlenden und unwirksamen Healthchecks zurück. 27 absichtlich unsichere Konfigurationen werden als Negativtests zurückgewiesen. **Bei ungültiger Konfiguration startet oder entfernt der Test keine Container oder Volumes.** Nach dem Containerstart kontrolliert er die von Docker tatsächlich veröffentlichten Adressen. Eine öffentlich erreichbare Datenbank oder Redis-Instanz führt zum Fehler und zum automatischen Cleanup. Danach werden Bereitschaft, PostgreSQL- und Redis-Version sowie `PING`/`PONG` geprüft. Zusätzlich führt die CI einen **echten Schreib-/Lese-Test mit einer temporären PostgreSQL-Tabelle** und einen **Redis-Cache-Test mit `SET`, `GET` und geprüftem automatischem Ablauf (`TTL`)** durch. Die Testdaten sind ausschließlich künstliche Werte und werden nach dem Lauf samt Test-Containern und Volumes entfernt:

```bash
bash scripts/ci/check_compose.sh
```

## Isolierter CI-Prüflauf und Regressionstests

Der Prüflauf lädt ausschließlich die versionierte `compose.yaml` mit einem neuen zufälligen Projektnamen. `COMPOSE_FILE`, `COMPOSE_PROJECT_NAME`, automatische Override-Dateien und eine lokale `.env` bestimmen diesen Lauf nicht. Fremde Netzwerke, Host-Namensräume, zusätzliche Fähigkeiten, Geräte, geänderte Startbefehle und weitere Laufzeit-Overrides werden abgewiesen.

Compose-Versionen können die Standard-Netzwerkkonfiguration ohne `ipam` oder mit einem leeren `ipam: {}` ausgeben. Beide Darstellungen sind zulässig. Eigene IPAM-Treiber, Subnetze, weitere Netzwerkfelder und zusätzliche Netzwerke werden abgewiesen.

Die aufgelöste Konfiguration wird in einem privaten temporären Verzeichnis geprüft und schreibgeschützt. Alle weiteren Docker-Befehle verwenden denselben Snapshot, einschließlich Bereinigung. Erneutes Einlesen muss exakt dieselbe Konfiguration liefern. Dollarzeichen bleiben für den Container geschützt; gleichnamige Host-Variablen können die Healthchecks nicht verändern. Änderungen der Quelldatei nach der Prüfung verändern den gestarteten oder entfernten Ressourcenbestand nicht.

Bei ungültiger Konfiguration startet oder entfernt der Prüfer keine Container oder Volumes. Nach begonnenem Start räumt er auch bei Fehlern und den Signalen `INT`, `TERM` und `HUP` nur sein eigenes Projekt auf. Eine gescheiterte Bereinigung lässt den Lauf fehlschlagen; ein vorheriger Fehlercode bleibt erhalten. Die Erfolgsmeldung erscheint erst nach erfolgreicher Bereinigung. Temporäre Konfigurationsdateien werden entfernt.

Zusätzlich zum echten PostgreSQL-/Redis-Test prüft CI den tatsächlichen Shell-Einstieg mit einem Docker-Testdouble. Dazu gehören abgewiesene Konfigurationen, unveränderte Snapshot-Verwendung, geschützte Variablen, Dienstfehler, Signalabbrüche und Bereinigungsfehler. Diese Regressionstests benötigen Python 3 und Bash und laufen auch ohne Docker-Daemon:

```bash
bash scripts/ci/check_compose.sh --self-test
```

## Lokale Dienste beenden

```bash
docker compose down --volumes --remove-orphans
```

Der Befehl entfernt auch die ausschließlich für diese lokale Umgebung erzeugten Testvolumes.
