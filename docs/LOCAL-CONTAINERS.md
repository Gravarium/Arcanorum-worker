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

Die vollständige, auch in CI verwendete Prüfung validiert die aufgelöste Compose-Konfiguration zusätzlich auf ausschließlich lokale Portbindungen (`127.0.0.1`), die vorgesehenen Images und Ports sowie fehlende privilegierte Host-Vernetzung. Vier absichtlich unsichere Portkonfigurationen werden als Negativtests zurückgewiesen. **Nach dem Containerstart kontrolliert der Test die von Docker tatsächlich veröffentlichten Adressen.** Eine öffentlich erreichbare Datenbank oder Redis-Instanz führt zum Fehler und zum automatischen Cleanup. Danach werden Bereitschaft, PostgreSQL- und Redis-Version sowie `PING`/`PONG` geprüft. Zusätzlich führt die CI einen **echten Schreib-/Lese-Test mit einer temporären PostgreSQL-Tabelle** und einen **Redis-Cache-Test mit `SET`, `GET` und geprüftem automatischem Ablauf (`TTL`)** durch. Die Testdaten sind ausschließlich künstliche Werte und werden nach dem Lauf samt Test-Containern und Volumes entfernt:

```bash
bash scripts/ci/check_compose.sh
```

## Stoppen und lokale Testdaten entfernen

```bash
docker compose down --volumes --remove-orphans
```

Der Befehl entfernt auch die ausschließlich für diese lokale Umgebung erzeugten Testvolumes.
