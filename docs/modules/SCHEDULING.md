# Zeitaufgabenmodul für den CMS-Arbeitsbereich

Das eigenständige Modul verwaltet wiederkehrende Wartungsaufgaben mit fester Aktions-Allowlist. Es speichert Aufgabenstatus, Fälligkeit und eine begrenzte Ausführungshistorie. Das Modul startet keine eigenen Cronjobs und führt keine Shell-Befehle, Benutzer-Skripte oder frei übergebenen Parameter aus.

## Funktionen und Grenzen

- Aufgaben werden angelegt, angezeigt, bearbeitet, pausiert, deaktiviert und gelöscht. Neue Aufgaben starten deaktiviert.
- Intervalle sind ganze Sekunden zwischen 60 und 2.592.000. Sie werden als verstrichene UTC-Zeit berechnet; die validierte IANA-Zeitzone steuert die Anzeige lokaler Fälligkeiten und Ausführungszeiten.
- Die einzigen akzeptierten Typen sind `cache_cleanup` und `public_index_refresh`. Für jeden aktivierbaren Typ muss die Host-Anwendung genau einen geprüften `ScheduledTaskHandler` bereitstellen. Die Registry lehnt doppelte Handler ab; unbekannte Typen und Typen ohne Handler werden abgewiesen.
- Der Handler erhält Aufgabenkennung, Fälligkeit und einen deterministischen Idempotenzschlüssel. Er bekommt keine vom Admin übermittelten Skripte, Shell-Befehle oder beliebigen Nutzdaten.
- Die Standardimplementierung `DenyAllSchedulingAdminGuard` verweigert Adminzugriffe geschlossen. Die Host-Anwendung bindet den Guard an ihre bestehende Admin-Berechtigung und CSRF-Prüfung.
- Ausführungsresultate enthalten nur einen festen Statuscode, Versuchszahl und Zeitstempel. Handler-Ausnahmen und ihre Nachrichten werden nicht gespeichert. Es bleiben höchstens 100 Resultate je Aufgabe erhalten.

## Zustands- und Ausführungsmodell

Eine neue Aufgabe ist `disabled`. Aktivieren setzt die erste Fälligkeit auf Intervall nach dem Aktivierungszeitpunkt. `active`-Aufgaben können pausiert oder deaktiviert werden; nur pausierte oder deaktivierte Aufgaben lassen sich bearbeiten. Gelöscht werden können nur deaktivierte Aufgaben ohne laufende Ausführung.

Die Host-Anwendung ruft `ScheduledTaskRunner::runDue()` aus ihrem eigenen, kontrollierten Laufzeitplan auf. Pro Aufruf werden höchstens 100 fällige Aufgaben betrachtet. Vor dem Handler-Aufruf beansprucht der DBAL-Speicher atomar eine eindeutige Kombination aus Aufgabe und Fälligkeit und vergibt eine 120-Sekunden-Lease. Eine parallele Ausführung erhält keinen zweiten Claim. Nach Rückkehr des Handlers liest der Runner die Abschlusszeit erneut aus seiner Uhr; ein Abschluss nach Ablauf der Lease wird abgewiesen. Ein anderer Prozess kann danach denselben Termin mit demselben Idempotenzschlüssel übernehmen. Handler müssen innerhalb der Lease abschließen und Seiteneffekte anhand dieses Schlüssels idempotent behandeln.

Ein alter Prozess kann nach einer erneuten Übernahme nicht mehr den neuen Claim abschließen. Nach zehn abgelaufenen Versuchen wird der Lauf als `abandoned` markiert und der Zeitplan auf den nächsten Termin weitergesetzt. Wird eine Aufgabe während einer Ausführung pausiert oder deaktiviert und später mit einem neuen Termin aktiviert, erfasst der Runner die abgelaufene alte Lease als `abandoned` mit dem festen Resultat `superseded`. Scheitert der Speicher beim Lesen, Beanspruchen oder Abschließen, meldet der Runner einen Fehler und behauptet keinen Erfolg. Fehlt zur Laufzeit ein Handler, wird die Ausführung als `handler_unavailable` erfasst und die Aufgabe bleibt geschlossen gegenüber beliebigem Ersatzcode.

## Admin-API

Der Controller stellt JSON-Routen unter `/admin/scheduling` bereit:

| Methode | Pfad | Zweck |
| --- | --- | --- |
| `GET` | `/tasks` | Aufgaben auflisten |
| `POST` | `/tasks` | Deaktivierte Aufgabe anlegen |
| `PATCH` | `/tasks/{id}` | Pausierte oder deaktivierte Aufgabe bearbeiten |
| `POST` | `/tasks/{id}/activate` | Aufgabe aktivieren |
| `POST` | `/tasks/{id}/pause` | Aktive Aufgabe pausieren |
| `POST` | `/tasks/{id}/disable` | Aufgabe deaktivieren |
| `DELETE` | `/tasks/{id}` | Deaktivierte Aufgabe und ihre Historie löschen |
| `GET` | `/tasks/{id}/executions` | Bis zu 100 Resultate abrufen |

Schreibende Aufrufe benötigen `X-CSRF-Token`; jede Admin-Route prüft zusätzlich die Berechtigung. Aufgabenfelder sind strikt begrenzt auf `label`, `type`, `interval_seconds` und `timezone`. Unbekannte Felder, darunter ein frei wählbarer Befehl, werden zurückgewiesen.

## Persistenzvertrag

`DoctrineSchedulingStore` nutzt Doctrine DBAL. Die Host-Anwendung übernimmt das folgende Schema in ihre eigene Migration und registriert die Modul-Routen sowie geprüfte Handler. Der Worker-Auftrag ändert keine gemeinsam genutzte Migration oder Symfony-Konfiguration.

```sql
CREATE TABLE cms_scheduled_task (
    id char(32) PRIMARY KEY,
    label varchar(120) NOT NULL,
    task_type varchar(32) NOT NULL CHECK (task_type IN ('cache_cleanup', 'public_index_refresh')),
    interval_seconds integer NOT NULL CHECK (interval_seconds BETWEEN 60 AND 2592000),
    timezone varchar(64) NOT NULL,
    status varchar(16) NOT NULL CHECK (status IN ('active', 'paused', 'disabled')),
    next_run_at timestamptz NULL,
    created_at timestamptz NOT NULL,
    updated_at timestamptz NOT NULL,
    CHECK ((status = 'active' AND next_run_at IS NOT NULL) OR (status <> 'active' AND next_run_at IS NULL))
);

CREATE INDEX cms_scheduled_task_due_idx ON cms_scheduled_task (next_run_at, id) WHERE status = 'active';

CREATE TABLE cms_scheduled_task_run (
    schedule_id char(32) NOT NULL REFERENCES cms_scheduled_task (id) ON DELETE CASCADE,
    scheduled_for timestamptz NOT NULL,
    status varchar(16) NOT NULL CHECK (status IN ('running', 'succeeded', 'failed', 'abandoned')),
    attempts integer NOT NULL CHECK (attempts BETWEEN 1 AND 10),
    result_code varchar(32) NULL CHECK (result_code IS NULL OR result_code IN ('completed', 'handler_failed', 'handler_unavailable', 'attempt_limit', 'superseded')),
    lease_token char(32) NULL,
    lease_expires_at timestamptz NULL,
    started_at timestamptz NOT NULL,
    finished_at timestamptz NULL,
    PRIMARY KEY (schedule_id, scheduled_for),
    CHECK (
        (status = 'running' AND result_code IS NULL AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL AND finished_at IS NULL)
        OR
        (status <> 'running' AND result_code IS NOT NULL AND lease_token IS NULL AND lease_expires_at IS NULL AND finished_at IS NOT NULL)
    )
);
```

Das `result_code`-Feld ist eine geschlossene Allowlist und enthält weder Ausnahme- noch Handlertexte. Gelöschte Aufgaben entfernen ihre begrenzten Resultate per Fremdschlüssel-Kaskade.

## Sichere Integration

Die Host-Anwendung registriert `SchedulingAdminGuard`, importiert `SchedulingAdminController`, mappt das Datenbankschema in eine reguläre Migration, taggt geprüfte `ScheduledTaskHandler`-Dienste und ruft den Runner über einen eigenen Scheduler auf. Handler sollen keine externen Befehle aus Nutzdaten erzeugen, ihren Idempotenzschlüssel vor Seiteneffekten verwenden und ausschließlich nicht vertrauliche feste Resultate liefern.

`403` bedeutet fehlende Adminberechtigung oder ungültiges CSRF-Token; `400` bedeutet ungültige Eingaben; `404` bedeutet unbekannte Aufgabe; `409` bedeutet einen unzulässigen Zustandswechsel oder eine parallele Änderung. Handlerfehler werden nur als fester Resultatcode in der Ausführungshistorie gespeichert.
