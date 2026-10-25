# Soziale Anmeldung im CMS-Arbeitsbereich

Dieses eigenstaendige Modul stellt sichere Ablauf- und Speichervertraege fuer die soziale Anmeldung bereit. Es enthaelt keine Provider-Zugangsdaten, echten OAuth-Aufrufe, Host-Routen, Datenbankmigrationen oder Admin-Oberflaeche. Providerzugriffe und dauerhafte Speicherung werden ausschliesslich ueber injizierte Schnittstellen angebunden.

## Provider und Registrierung

Die feste Provider-Allowlist enthaelt `google`, `facebook` und `twitter`. Jeder Provider hat einen eigenen Aktivierungsstatus. Ein unbekannter Provider oder Flow wird geschlossen abgelehnt. Die Registrierung besitzt einen getrennten Schalter und wird sowohl beim Start als auch beim Callback geprueft, bevor Providerdaten verarbeitet werden. Der Login bestehender Konten legt nie automatisch ein Konto an.

## OAuth- und Sitzungsvertrag

- Fuer jeden Start erzeugt das Modul mit `random_bytes()` einen einmaligen State und einen PKCE-Verifier. Es sendet ausschliesslich die S256-Challenge an den Provider.
- Der rohe State wird nicht gespeichert. `OAuthStateStore` erhaelt seinen SHA-256-Digest und muss den zugehoerigen Datensatz beim Callback atomar genau einmal verbrauchen. Der Datensatz laeuft standardmaessig nach fuenf Minuten ab.
- Der Host uebergibt einen mindestens 32 Zeichen langen, kryptografisch zufaelligen Sitzungsbezug, der nur in derselben Browser-Sitzung verfuegbar ist. Das Modul speichert nur dessen SHA-256-Digest und vergleicht ihn beim Callback konstantzeitlich. Ein korrektes OAuth-State allein ersetzt diese Browserbindung nicht.
- Autorisierungs-URLs, State-Werte, Sitzungsbezug und Callback-Querys duerfen weder in Anwendungsprotokolle noch in Analyseereignisse geschrieben werden.
- Der Datensatz enthaelt den PKCE-Verifier, den festen Callback und bei einem Verknuepfungsflow die Konto-ID. Er darf nur kurzfristig und geschuetzt gespeichert werden. Der Host muss ihn nach Ablauf automatisch entfernen.
- Callback-URIs sind fest konfigurierte HTTPS-Adressen ohne Nutzerinfo, Port, Query oder Fragment. OAuth-Endpunkte muessen HTTPS verwenden und exakt zum konfigurierten Provider-Host passen. Der Host darf keine frei waehlbare Ruecksprung-URL an den Ablauf anhaengen.
- Der Provideradapter liefert nur eine normalisierte Identitaetsantwort. Das Modul verarbeitet keine unbeschraenkten Providerantworten; die Standardgrenze liegt bei 16 KiB und kann hoechstens 64 KiB betragen.

Beim Start und Callback muss der Host den passenden Sitzungsbezug weiterreichen. Beim Verknuepfen muss er ausserdem im Callback das aktuell angemeldete Konto uebergeben. Das Modul vergleicht es mit der kurzlebig gespeicherten Konto-ID und verlangt eine erneute Authentifizierung innerhalb des konfigurierten Zeitfensters.

## Identitaeten und Kontoschutz

Providerkonten werden ausschliesslich ueber das Paar aus Provider und stabilem Provider-Subjekt identifiziert. E-Mail-Adressen werden nie zum automatischen Zusammenfuehren von Konten verwendet. Das Modul reicht eine E-Mail nur als `VerifiedEmail` weiter, wenn der Adapter `email_verified: true` bestaetigt und die Adresse gueltig ist.

`SocialIdentityRepository` muss die folgenden Operationen transaktional und mit eindeutigen Datenbankbedingungen umsetzen:

- `accountIdFor()` liest nur die exakte Provider-Subjekt-Zuordnung.
- `registerAccount()` legt ein Konto nur an, wenn die Identitaet noch keinem Konto gehoert.
- `linkToAccount()` fuegt eine unbenutzte Identitaet hinzu und ueberschreibt niemals eine vorhandene Zuordnung.
- `unlinkIfAnotherMethodRemains()` loescht nur atomar, wenn danach noch mindestens eine andere Anmeldemethode existiert.

Der Host erstellt `AuthenticatedAccount` nur aus dem bestehenden Berechtigungs- und Sitzungspruefer. Eine vom Aufrufer gelieferte Konto-ID allein ist kein Berechtigungsnachweis. Konfigurationsaenderungen im Adminbereich muessen die vorhandenen Berechtigungspruefungen und CSRF-Schutz verwenden.

## Fehler, Drosselung und Daten

Fehler werden nach aussen mit einer allgemeinen Anmeldungsablehnung beantwortet. Providerfehler, Tokens und Antworttexte werden vom Modul weder gespeichert noch protokolliert; es gibt keine Logger-Abhaengigkeit. `AuthenticationRateLimiter` ist injiziert, erhaelt fuer angemeldete Konten nur einen SHA-256-Kontoschluessel und kann zusaetzlich aktuelle Anfragekontexte begrenzen. Das Modul selbst speichert keine IP-Adressen oder Browserprofile.

Der Host speichert nur die fuer die Kontozuordnung erforderlichen Werte: Provider, stabiles Subjekt und optional eine verifizierte E-Mail, sofern sie fuer den Hostprozess gebraucht wird. Access-/Refresh-Tokens und rohe Providerantworten gehoeren nicht in dieses Modul. Tests verwenden ausschliesslich Fakes und senden keine externen Anfragen.

## Host-Anbindung

1. `SocialAuthSettings` mit den drei festen Providern, getrennten Aktivierungsstatus, einem separaten Registrierungsstatus, festen Callback-URIs, Providerhosts und begrenzten TTL-/Antwortwerten erstellen.
2. Je Provider einen `OAuthProviderClient` injizieren. Die Methode `authorizationParameters()` liefert nur oeffentliche Parameter wie Client-ID und Scopes; die Pflichtwerte fuer Callback, State und PKCE erstellt das Modul selbst. Der Codeaustausch muss jede rohe Providerantwort bereits beim Lesen auf die uebergebene Bytegrenze begrenzen und darf nur die normalisierte Identitaet zurueckgeben. Geheimnisse bleiben in der bestehenden sicheren Host-Konfiguration und werden nur serverseitig beim Codeaustausch verwendet.
3. `OAuthStateStore` mit atomarem `consume()` aufbauen. Den vom Modul uebergebenen Digest als Schluessel verwenden und den PKCE-Verifier nur kurzzeitig geschuetzt halten.
4. `SocialIdentityRepository` mit eindeutiger Provider-/Subjekt-Bedingung, transaktionaler Registrierung und atomarer Verknuepfungs-/Trennpruefung implementieren. Das Modul legt keine gemeinsame Migration fest.
5. Host-Routen fuer Start und Callback anlegen. Den Sitzungsbezug aus dem aktuellen sicheren Sitzungskontext ableiten. Fuer Verknuepfungen das aktuelle Konto und den Zeitpunkt der letzten erneuten Authentifizierung liefern. Der Callback gibt nur einen vom Host festgelegten sicheren Zielpfad frei.
6. `AuthenticationRateLimiter` an den bestehenden Rate-Limiter anbinden. IP- oder Konto-IDs nicht in Modulprotokollen speichern.
7. Admin-Schalter fuer Provider und Registrierung an die bestehenden Berechtigungs- und CSRF-Gates anbinden. Ein deaktivierter oder unbekannter Provider darf keine Weiterleitung oder Identitaetsverarbeitung starten.

## Oeffentliche Schnittstellen

- `SocialAuthService::begin()` erstellt eine begrenzte OAuth-Weiterleitung.
- `SocialAuthService::complete()` verbraucht State, prueft Sitzung und PKCE-Kontext und liefert nur Konto-ID, Provider und Flow zurueck.
- `SocialAuthService::unlink()` trennt nach erneuter Authentifizierung eine Provideridentitaet, wenn eine weitere Anmeldemethode verbleibt.
- `OAuthProviderClient`, `OAuthStateStore`, `SocialIdentityRepository` und `AuthenticationRateLimiter` sind Hostadapter-Vertraege; sie enthalten keine Implementierung eines externen Dienstes.

Das Modul startet keine Hintergrundjobs und fuehrt keine echten Provideranfragen aus. Seine Integration muss im Hostprojekt separat geprueft werden.
