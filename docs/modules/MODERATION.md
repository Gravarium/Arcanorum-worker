# Mitarbeiterzugriff und Moderationsrechte

Dieses Modul bildet eine feste, klein gehaltene Berechtigungsmatrix ab:

- Administratoren dürfen Videos und Kommentare moderieren.
- Allgemeine Moderatoren dürfen Videos und Kommentare moderieren.
- Videomoderatoren dürfen nur Videos moderieren.
- Kommentarmoderatoren dürfen nur Kommentare moderieren.
- Mitglieder und unbekannte Rollen erhalten keine Moderationsrechte.
- `ROLE_USER` ist ausschließlich die Basisrolle und kann nicht als Mitarbeiterrolle zugewiesen werden.

Die Richtlinien werten nur die bekannte, direkt gespeicherte Rollenliste aus. Eine unbekannte oder fehlerhaft geformte Rolle macht die gesamte Eingabe ungültig und führt zu `false`; sie erweitert keine vorhandenen Rechte. Abgeleitete Symfony-Rollen wie `ROLE_ADMIN_STAFF` werden nicht als gespeicherte Mitarbeiterrollen akzeptiert.

## Rollenvergabe

`ModeratorAssignmentPolicy` ist eine reine Entscheidungskomponente. Die Anwendung muss sie innerhalb einer Datenbanktransaktion mit frisch geladenen und gesperrten Mitarbeiterkonten sowie einer ebenfalls frisch gezählten Administratorzahl aufrufen. Die Aktionsroute prüft CSRF, bevor sie den Aufruf startet. MFA-Zustände stammen aus dem vertrauenswürdigen Kontodatensatz und der aktiven Sitzung, nicht aus Formularwerten. Nur `Allowed` führt zu einer Änderung und zu einem getrennten Aktivitätsprotokoll. Datenbank- oder Sperrfehler brechen die Transaktion ab.

Die Richtlinie schützt vor Selbständerung, Rollenvergabe ohne MFA am Zielkonto, Entzug des letzten Administrators, ungültigen Identitäten und veraltetem oder unbekanntem Rollenstand. Die umgebende Anwendung bleibt für Anmeldung, Sitzung, MFA-Verifikation, CSRF-Token, Transaktion, Speicherung, Audit und HTTP-Antwort verantwortlich. Ein Formularwert wird nur als `null` für ausdrücklich gewählten Entzug oder als gültiger, zuweisbarer Wert aus `ModeratorRole::tryFrom()` akzeptiert; unbekannte Werte werden als Eingabefehler abgewiesen.

Das Modul enthält weder Benutzerkonten noch Geheimnisse, Datenbankverbindungen oder Produktionskonfiguration. Es stellt keine Route oder Adminoberfläche bereit. Die Host-Anwendung muss jede Video- und Kommentaraktion zusätzlich gegen die Ressource, den wirksamen Rollenstand und ihre eigene Sicherheitsrichtlinie prüfen.
