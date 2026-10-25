<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AdminSettingsTranslationTest extends KernelTestCase
{
    public function testEnglishCatalogContainsAdminAndSettingsMessages(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Verwaltung' => 'Administration',
            'Rollen und Rechte' => 'Roles and permissions',
            'Allgemeine Einstellungen' => 'General settings',
            'Englisch' => 'English',
            'Deutsch und Englisch sind als vollständige Sprachpakete verfügbar.' => 'German and English are available as complete language packs.',
            'Bitte wählen Sie eine installierte Sprache aus.' => 'Choose a language available in this installation.',
            'Neu veröffentlichte News und Artikel.' => 'Recently published news and articles.',
            'Neu veröffentlichte Videos.' => 'Recently published videos.',
            'Video und Wiedergabe' => 'Video and playback',
            'Theme und Layout' => 'Theme and layout',
            'E-Mail-Einstellungen' => 'Email settings',
            'Mitglieder und Anmeldung' => 'Members and sign-in',
            'Besucherkommentare und deren Moderation.' => 'Visitor comments and their moderation.',
            'Feeds und Robots' => 'Feeds and robots',
            'Robots-Regeln, RSS-Feeds und Sitemaps.' => 'Robots rules, RSS feeds, and sitemaps.',
            'Kartenraster' => 'Grid',
            'Bestanden' => 'Verified',
            'PostgreSQL 18' => 'PostgreSQL 18',
            'Bitte wählen Sie eine gültige sechsstellige Hex-Farbe.' => 'Choose a valid six-digit hexadecimal color.',
            'Der E-Mail-Versand ist noch nicht vollständig eingerichtet. Die Transportzugangsdaten müssen in der geschützten Serverkonfiguration liegen.' => 'Email delivery is not fully configured. Transport credentials must be stored in the protected server configuration.',
            'Die Testnachricht konnte nicht zugestellt werden. Prüfen Sie die geschützte Serverkonfiguration.' => 'The test message could not be delivered. Check the protected server configuration.',
            'Das Modul kann erst aktiviert werden, wenn folgende Hostingfähigkeiten aktuell nachgewiesen sind: PostgreSQL 18.' => 'This module cannot be enabled until the following hosting capability has been verified: PostgreSQL 18.',
            'Einstellungen speichern' => 'Save settings',
            'Nur notwendige' => 'Essential only',
            'Ihre Auswahl' => 'Your selection',
            'Entdecken' => 'Explore',
            'Suchergebnisseiten' => 'Search result pages',
            'Information' => 'Information',
            'Suchbegriffe' => 'Search terms',
            'System prüfen' => 'Check system',
            'Filterwort hinzufügen' => 'Add filter term',
            'Wortfilter hinzufügen' => 'Add filter word',
            'Freigeben' => 'Approve',
            'Webseite' => 'Website',
        ];
        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }
    }

    public function testSearchStatisticsAdministrationMessagesHaveEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Suchstatistik' => 'Search statistics',
            'Suchbegriffe werden je Tag zusammengefasst und nach 30 Tagen entfernt. Nutzerkonten und Netzwerkadressen werden nicht gespeichert.' => 'Search terms are grouped by day and removed after 30 days. User accounts and network addresses are not stored.',
            'Suchbegriff filtern' => 'Filter search terms',
            'Suchen' => 'Search',
            'Suchstatistik leeren' => 'Clear search statistics',
            'Keine Suchbegriffe gefunden.' => 'No search terms were found.',
            'Tag (UTC)' => 'Day (UTC)',
            'Suchbegriff' => 'Search term',
            'Zuletzt (UTC)' => 'Last activity (UTC)',
            'Seitenwechsel' => 'Pagination',
            'Vorherige Seite' => 'Previous page',
            'Nächste Seite' => 'Next page',
        ];
        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            '42 entries',
            $translator->trans('%count% Einträge', ['%count%' => 42], 'messages', 'en'),
        );
        self::assertSame(
            'Page 2 of 4',
            $translator->trans('Seite %page% von %total%', ['%page%' => 2, '%total%' => 4], 'messages', 'en'),
        );
    }

    public function testContentStatisticsAdministrationMessagesHaveEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Inhaltsstatistik' => 'Content statistics',
            'Neu erstellte Videos, News, Kommentare und Mitgliedskonten nach UTC-Tag. Es werden keine personenbezogenen Einzelereignisse ausgegeben.' => 'New videos, news posts, comments, and member accounts by UTC day. No personally identifiable individual events are displayed.',
            'Statistikzeitraum' => 'Statistics period',
            '7 Tage' => '7 days',
            '30 Tage' => '30 days',
            '90 Tage' => '90 days',
            'Tag (UTC)' => 'Day (UTC)',
            'Videos' => 'Videos',
            'News' => 'News',
            'Kommentare' => 'Comments',
            'Mitglieder' => 'Members',
        ];
        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'During this period: 12 videos, 5 news posts, 8 comments, and 3 member accounts.',
            $translator->trans(
                'Im Zeitraum: %videos% Videos, %news% News, %comments% Kommentare und %members% Mitgliedskonten.',
                ['%videos%' => 12, '%news%' => 5, '%comments%' => 8, '%members%' => 3],
                'messages',
                'en',
            ),
        );
    }

    public function testAdminNewsAndTagWorkflowsHaveEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Beiträge suchen, Entwürfe prüfen und Veröffentlichungen vorbereiten.' => 'Search posts, review drafts, and prepare publications.',
            'News verfassen' => 'Write news',
            'Alle Beiträge' => 'All posts',
            'Auswahlaktion' => 'Bulk action',
            'Auf Auswahl anwenden' => 'Apply to selection',
            'Wähle bis zu 50 Einträge aus. Löschen entfernt auch ihre Kommentare.' => 'Select up to 50 entries. Deleting an entry also deletes its comments.',
            'Noch keine Beiträge. Verfasse den ersten News-Beitrag.' => 'There are no posts yet. Write the first news article.',
            'Text wird als sicherer Klartext gespeichert und in der Vorschau escaped ausgegeben.' => 'Text is stored as plain text and escaped in the preview.',
            'News-Beitrag wurde gespeichert.' => 'The news article was saved.',
            'Dieses Schlagwort ist bereits vorhanden.' => 'This tag already exists.',
            'Der Schlagwortname ist erforderlich und darf höchstens 100 Zeichen lang sein.' => 'A tag name is required and must be no more than 100 characters.',
            'Schlagwort wurde angelegt.' => 'The tag was created.',
            'Schlagwort wurde gespeichert.' => 'The tag was saved.',
            'Schlagwort wurde nicht gefunden.' => 'The tag could not be found.',
            'Das Schlagwort wird noch verwendet und kann nicht gelöscht werden.' => 'This tag is still in use and cannot be deleted.',
            'Schlagwort wurde gelöscht.' => 'The tag was deleted.',
            'Eine Umbenennung gilt sofort für alle zugeordneten News- und Videoinhalte.' => 'Renaming a tag immediately updates all associated news and videos.',
            'Der Inhalt ist erforderlich und darf höchstens 100.000 Zeichen lang sein.' => 'A body is required and must be no more than 100,000 characters.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            '17 results · page 2 of 4',
            $translator->trans(
                '%result_count% Treffer · Seite %page% von %page_count%',
                ['%result_count%' => 17, '%page%' => 2, '%page_count%' => 4],
                'messages',
                'en',
            ),
        );
        self::assertSame(
            '6 items were published.',
            $translator->trans(
                '%count% Einträge wurden %action%.',
                ['%count%' => 6, '%action%' => $translator->trans('veröffentlicht', [], 'messages', 'en')],
                'messages',
                'en',
            ),
        );
        self::assertSame(
            'Select Sunrise',
            $translator->trans('%title% auswählen', ['%title%' => 'Sunrise'], 'messages', 'en'),
        );
    }

    public function testEnglishStaticPageAdministrationMessagesAreTranslated(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Website' => 'Website',
            'Seiten verwalten' => 'Manage pages',
            'Statische Seiten' => 'Static pages',
            'Neue Seite' => 'New page',
            'Seiten' => 'Pages',
            'Inhalte wie Kontakt- und Informationsseiten bearbeiten und veröffentlichen.' => 'Edit and publish contact and information pages.',
            'Suchen' => 'Search',
            'Titel oder Adresse' => 'Title or address',
            'Alle Seiten' => 'All pages',
            'Entwürfe' => 'Drafts',
            'Veröffentlicht' => 'Published',
            'Filtern' => 'Filter',
            'Titel' => 'Title',
            'Adresse' => 'Address',
            'Status' => 'Status',
            'Zuletzt geändert' => 'Last modified',
            'Aktion' => 'Action',
            'Noch keine Seiten vorhanden.' => 'There are no pages yet.',
            'Der Seiteninhalt wird als Klartext gespeichert und sicher ausgegeben.' => 'Page content is stored as plain text and safely rendered.',
            'Bitte prüfe die Eingaben.' => 'Check the entered values.',
            'Seitendaten' => 'Page details',
            'Die Adresse wird aus dem Titel gebildet und bleibt im Entwurf verborgen.' => 'The address is generated from the title and remains hidden while the page is a draft.',
            'Seite bearbeiten' => 'Edit page',
            'Bearbeiten' => 'Edit',
            'Entwurf' => 'Draft',
            'Inhalt' => 'Content',
            'Zurück zur Übersicht' => 'Back to overview',
            'Seite speichern' => 'Save page',
            'Änderungen speichern' => 'Save changes',
            'Abbrechen' => 'Cancel',
            'Seite wurde gespeichert.' => 'The page was saved.',
            'Die Kurzbeschreibung darf höchstens 500 Zeichen lang sein.' => 'The summary must be no longer than 500 characters.',
            'Der Seiteninhalt ist erforderlich und darf höchstens 100.000 Zeichen lang sein.' => 'Page content is required and must be no longer than 100,000 characters.',
            'Für diesen Titel gibt es bereits eine Seite.' => 'A page with this title already exists.',
            'Kurzbeschreibung' => 'Summary',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            '3 results in this view',
            $translator->trans('Treffer in dieser Ansicht: %result_count%', ['%result_count%' => 3], 'messages', 'en'),
        );
    }

    public function testEnglishMemberAdministrationMessagesAreTranslated(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Nutzerkonten' => 'Member accounts',
            'Nutzer' => 'Users',
            'Mitglieder' => 'Members',
            'Mitgliedskonten' => 'Member accounts',
            'Mitgliedskonten suchen, Profile bearbeiten und ihren Kontostatus verwalten.' => 'Search member accounts, edit profiles, and manage account status.',
            'Mitglied anlegen' => 'Create member',
            'CSV exportieren' => 'Export CSV',
            'E-Mail, Kanalname oder Anzeigename' => 'Email, channel name, or display name',
            'Kontostatus' => 'Account status',
            'Alle Konten' => 'All accounts',
            'Aktive Konten' => 'Active accounts',
            'Gesperrte Konten' => 'Locked accounts',
            'Suchen' => 'Search',
            'Kanalname' => 'Channel name',
            'Anzeigename' => 'Display name',
            'E-Mail' => 'Email',
            'Status' => 'Status',
            'Profil' => 'Profile',
            'Registriert' => 'Registered',
            'Aktionen' => 'Actions',
            'Aktiv' => 'Active',
            'Gesperrt' => 'Blocked',
            'Öffentlich' => 'Public',
            'Privat' => 'Private',
            'Konto bearbeiten' => 'Edit account',
            'Keine Nutzerkonten gefunden.' => 'No member accounts found.',
            'Seitennavigation' => 'Pagination',
            'Vorherige' => 'Previous',
            'Nächste' => 'Next',
            'Mitgliederprofil bearbeiten' => 'Edit member profile',
            'Profil bearbeiten' => 'Edit profile',
            'Einmaliger Passwort-Reset-Code' => 'One-time password reset code',
            'Einmaliger Einrichtungscode' => 'One-time setup code',
            'Einmaliger Passwort-Reset-Code — nur jetzt sichtbar' => 'One-time password reset code — visible only now',
            'Das Konto bleibt gesperrt, bis das Mitglied hier ein neues Passwort festlegt. Der Code läuft nach 48 Stunden ab. Es wird keine E-Mail versendet.' => 'The account stays suspended until the member sets a new password here. The code expires after 48 hours. No email is sent.',
            'Einrichtungscode — nur jetzt sichtbar' => 'Setup code — visible only now',
            'Teile diesen Code ausschließlich über einen sicheren, vertrauenswürdigen Kanal. Er läuft nach 48 Stunden ab; automatischer E-Mail-Versand ist nicht eingerichtet.' => 'Share this code only through a secure, trusted channel. It expires after 48 hours; automatic email delivery is not configured.',
            'Das Mitglied öffnet' => 'The member opens',
            'die Kontoeinrichtung' => 'account setup',
            'und gibt den Code dort ein.' => 'and enters the code there.',
            'Kontoeinrichtung' => 'Account setup',
            'Aktive Konten benötigen keinen Einrichtungscode.' => 'Active accounts do not need a setup code.',
            'Der aktuelle Code läuft am' => 'The current code expires at',
            'ab. Ein neuer Code macht den bisherigen ungültig.' => '; a new code invalidates the previous code.',
            'Das Konto ist inaktiv und hat noch keinen Einrichtungscode.' => 'This account is inactive and does not have a setup code yet.',
            'Neuen Einrichtungscode ausstellen' => 'Issue a new setup code',
            'Einrichtungscode ausstellen' => 'Issue setup code',
            'Aktiv. Eine Sperre beendet auch bestehende Anmeldesitzungen.' => 'Active. Suspending the account also ends existing sign-in sessions.',
            'Gesperrt. Das Mitglied kann sich erst nach der Aktivierung wieder anmelden.' => 'Blocked. The member cannot sign in again until the account is reactivated.',
            'Konto sperren' => 'Suspend account',
            'Konto aktivieren' => 'Activate account',
            'Passwort zurücksetzen' => 'Reset password',
            'Der Reset sperrt das Konto und beendet bestehende Sitzungen sofort. Der einmalige Code wird ohne E-Mail-Versand angezeigt und muss über einen vertrauenswürdigen Kanal weitergegeben werden.' => 'The reset suspends the account and immediately ends existing sessions. The one-time code is shown without email delivery and must be shared through a trusted channel.',
            'Passwort-Reset-Code ausstellen' => 'Issue password reset code',
            'Der Reset ist nur für aktive Konten verfügbar. Aktiviere dieses Konto erst, wenn kein Einrichtungscode mehr offen ist.' => 'Password resets are available only for active accounts. Activate this account only when no setup code is pending.',
            'Anmeldung und Kanal' => 'Sign-in and channel',
            'Die E-Mail-Adresse ist die Anmeldekennung. Kanalnamen müssen eindeutig sein.' => 'The email address is the sign-in identifier. Channel names must be unique.',
            'Bitte prüfe E-Mail-Adresse und Kanalname.' => 'Check the email address and channel name.',
            'Anmeldung und Kanal speichern' => 'Save sign-in and channel',
            'Profildaten' => 'Profile details',
            'Öffentliche Profildaten und Profilsichtbarkeit.' => 'Public profile details and profile visibility.',
            'Profilbeschreibung' => 'Profile description',
            'Öffentliches Profil' => 'Public profile',
            'Die Eingabe ist zu groß.' => 'The input is too large.',
            'Die Eingabe muss gültiges UTF-8 enthalten.' => 'The input must contain valid UTF-8.',
            'Bitte gib eine gültige E-Mail-Adresse ein.' => 'Enter a valid email address.',
            'Der Kanalname muss 3 bis 32 Zeichen lang sein und darf Buchstaben, Zahlen, Unterstriche und Bindestriche enthalten.' => 'The channel name must be 3 to 32 characters and may contain letters, numbers, underscores, and hyphens.',
            'Der Anzeigename muss 1 bis 80 Zeichen lang sein.' => 'The display name must be between 1 and 80 characters.',
            'Diese E-Mail-Adresse oder dieser Kanalname ist bereits vergeben.' => 'This email address or channel name is already in use.',
            'Mitgliedskonto angelegt. Der Einrichtungscode wird auf der nächsten Seite einmalig angezeigt.' => 'Member account created. The setup code will be shown once on the next page.',
            'Der Anzeigename oder die Profilbeschreibung ist zu lang.' => 'The display name or profile description is too long.',
            'Die Profilsichtbarkeit ist ungültig.' => 'The profile visibility value is invalid.',
            'Mitgliederprofil gespeichert.' => 'Member profile saved.',
            'Nur für inaktive Konten kann ein Einrichtungscode ausgestellt werden.' => 'A setup code can be issued only for inactive accounts.',
            'Ein neuer Einrichtungscode wurde ausgestellt; der vorherige Code ist ungültig.' => 'A new setup code was issued; the previous code is no longer valid.',
            'Ein Passwort-Reset kann nur für aktive Konten gestartet werden.' => 'A password reset can be started only for active accounts.',
            'Das Mitgliedskonto wurde bis zur Passwortneufestlegung gesperrt. Der Reset-Code wird nur einmal angezeigt.' => 'The member account is suspended until a new password is set. The reset code is shown only once.',
            'Die E-Mail-Adresse oder der Kanalname ist zu lang.' => 'The email address or channel name is too long.',
            'E-Mail-Adresse oder Kanalname enthält ungültige Angaben.' => 'The email address or channel name contains invalid values.',
            'E-Mail-Adresse und Kanalname gespeichert. Eine Bestätigungsnachricht wurde an die neue E-Mail-Adresse gesendet.' => 'Email address and channel name saved. A verification message was sent to the new email address.',
            'E-Mail-Adresse und Kanalname gespeichert. Das Mitglied kann die Bestätigungsnachricht über die Anmeldeseite erneut anfordern.' => 'Email address and channel name saved. The member can request the verification message again from the sign-in page.',
            'E-Mail-Adresse und Kanalname gespeichert.' => 'Email address and channel name saved.',
            'E-Mail-Adresse oder Kanalname ist bereits vergeben.' => 'The email address or channel name is already in use.',
            'Ungültiger Kontostatus.' => 'Invalid account status.',
            'Mitgliedskonto aktiviert.' => 'Member account activated.',
            'Mitgliedskonto gesperrt.' => 'Member account suspended.',
            'Das Mitgliedskonto enthält ungültige Angaben.' => 'The member account contains invalid values.',
            'Das Profil enthält ungültige Angaben.' => 'The profile contains invalid values.',
            'Der Einrichtungscode ist ungültig.' => 'The setup code is invalid.',
            'Kontodaten' => 'Account details',
            'Profilsichtbarkeit' => 'Profile visibility',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            '27 results · page 2 of 2',
            $translator->trans(
                '%result_count% Treffer · Seite %page% von %page_count%',
                ['%result_count%' => 27, '%page%' => 2, '%page_count%' => 2],
                'messages',
                'en',
            ),
        );
    }

    public function testEnglishAdminHostingAndRuntimeLabelsAreTranslated(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Ausgewählte Laufzeitwerte und aktuelle Systemhinweise. Geheimnisse, Umgebungsvariablen und Konfigurationspfade werden nicht angezeigt.' => 'Selected runtime values and current system notices. Secrets, environment variables, and configuration paths are not displayed.',
            'Die Prüfung der Fähigkeiten ersetzt keine unabhängige Sicherheitsattestierung.' => 'Capability checks do not replace independent security attestation.',
            'Hosting-Fähigkeiten' => 'Hosting capabilities',
            'Fähigkeit' => 'Capability',
            'PHP-Konfiguration' => 'PHP configuration',
            'PHP-Version' => 'PHP version',
            'Laufzeit' => 'Runtime',
            'Begrenzte PHP-Laufzeitwerte' => 'Selected PHP runtime limits',
            'Grenze' => 'Limit',
            'Wert' => 'Value',
            'PHP-Einstellungen' => 'PHP settings',
            'Ausgewählte PHP-Sicherheits- und Laufzeiteinstellungen' => 'Selected PHP security and runtime settings',
            'Einstellung' => 'Setting',
            'PHP-Erweiterungen' => 'PHP extensions',
            'Verfügbarkeit erforderlicher und optionaler PHP-Erweiterungen' => 'Availability of required and optional PHP extensions',
            'Erweiterung' => 'Extension',
            'Maximale Uploadgröße' => 'Maximum upload size',
            'Maximale POST-Größe' => 'Maximum POST size',
            'Speichergrenze' => 'Memory limit',
            'Zeitlimit pro Anfrage' => 'Maximum execution time',
            'Zeitlimit für Eingaben' => 'Maximum input time',
            'Maximale Eingabefelder' => 'Maximum input variables',
            'Maximale Dateiübertragungen' => 'Maximum file uploads',
            'Socket-Zeitlimit' => 'Socket timeout',
            'Internationalisierung' => 'Internationalization',
            'Multibyte-Zeichen' => 'Multibyte strings',
            'PostgreSQL PDO' => 'PostgreSQL PDO',
            'Dateitypprüfung' => 'File type detection',
            'OPcache' => 'OPcache',
            'Assertionsmodus' => 'Assertions mode',
            'Fehlerausgabe' => 'Error display',
            'Fehlerprotokollierung' => 'Error logging',
            'PHP-Version im HTTP-Header' => 'PHP version in HTTP header',
            'URL-Includes' => 'URL includes',
            'URL-Dateizugriff' => 'URL file access',
            'Separater Fehlerkanal' => 'Separate error channel',
            'Aktiviert' => 'Enabled',
            'Deaktiviert' => 'Disabled',
            'Verfügbar' => 'Available',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }
    }

    public function testEnglishHostingCapabilityAndEvidenceMessagesAreTranslated(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'PHP-Laufzeit' => 'PHP runtime',
            'PHP-Erweiterung ctype' => 'PHP extension ctype',
            'PHP-Erweiterung iconv' => 'PHP extension iconv',
            'PHP-Erweiterung intl' => 'PHP extension intl',
            'PHP-Erweiterung json' => 'PHP extension json',
            'PHP-Erweiterung mbstring' => 'PHP extension mbstring',
            'PDO' => 'PDO',
            'PDO PostgreSQL' => 'PDO PostgreSQL',
            'PHP-Erweiterung sodium' => 'PHP extension sodium',
            'PHP-Erweiterung zip' => 'PHP extension zip',
            'PostgreSQL 18' => 'PostgreSQL 18',
            'Redis 8' => 'Redis 8',
            'Privater Dateispeicher' => 'Private file storage',
            'Getrennte Datenbankrollen' => 'Separate database roles',
            'Private Redis-Anbindung' => 'Private Redis endpoint',
            'Hintergrundprozess' => 'Background worker',
            'Prozessisolation' => 'Process isolation',
            'Ausgehende Netzwerkregeln' => 'Outbound network policy',
            'Geheimnisspeicher' => 'Secret store',
            'Geprüfte Sicherungswiederherstellung' => 'Verified backup restore',
            'Bestanden' => 'Verified',
            'Fehlgeschlagen' => 'Failed',
            'Nicht verfügbar' => 'Unavailable',
            'Unbekannt' => 'Unknown',
            'Der öffentliche Webpfad konnte nicht geprüft werden.' => 'The public web path could not be checked.',
            'Der Speicherpfad liegt innerhalb des öffentlichen Webpfads oder ist nicht absolut.' => 'The storage path is inside the public web path or is not absolute.',
            'Ein vorhandener Pfadbestandteil führt in den öffentlichen Webpfad.' => 'An existing path component resolves into the public web path.',
            'Der private Speicher darf kein symbolischer Link sein.' => 'Private storage must not be a symbolic link.',
            'Der private Speicher kann nicht sicher angelegt werden.' => 'Private storage could not be created safely.',
            'Der private Speicherpfad konnte nicht aufgelöst werden.' => 'The private storage path could not be resolved.',
            'Der private Speicher ist nicht beschreibbar.' => 'Private storage is not writable.',
            'Der Schreibtest im privaten Speicher war nicht erfolgreich.' => 'The private storage write test did not succeed.',
            'PHP-URL-Includes sind aktiviert und sollten deaktiviert werden.' => 'PHP URL includes are enabled and should be disabled.',
            'PHP-Fehlerausgabe ist in der Produktionsumgebung aktiviert.' => 'PHP error display is enabled in the production environment.',
            'Für diese Fähigkeit liegt noch kein prüfbarer Nachweis vor.' => 'No verifiable evidence is available for this capability yet.',
            'Mehrere Prüfer meldeten dieselbe Fähigkeit; der Nachweis ist mehrdeutig.' => 'Multiple probes reported the same capability, so the evidence is ambiguous.',
            'Die PostgreSQL-Verbindung oder Versionsprüfung ist nicht verfügbar.' => 'The PostgreSQL connection or version check is unavailable.',
            'Die PostgreSQL-Serverversion konnte nicht zuverlässig gelesen werden.' => 'The PostgreSQL server version could not be read reliably.',
            'Die aktive PostgreSQL-Verbindung meldet die geforderte Hauptversion 18.' => 'The active PostgreSQL connection reports the required major version 18.',
            'Die aktive PostgreSQL-Verbindung erfüllt die Projektvorgabe PostgreSQL 18 nicht.' => 'The active PostgreSQL connection does not meet the PostgreSQL 18 project requirement.',
            'Der Redis-Dienst oder seine Versionsabfrage ist nicht verfügbar.' => 'The Redis service or its version query is unavailable.',
            'Der Redis-Dienst erfüllt die Projektvorgabe Redis 8 nicht.' => 'The Redis service does not meet the Redis 8 project requirement.',
            'Der Redis-Endpunkt verwendet keine private oder lokale IP-Adresse.' => 'The Redis endpoint does not use a private or local IP address.',
            'Für diese Redis-DSN ist kein DNS-unabhängiger Nachweis einer privaten Zieladresse verfügbar.' => 'No DNS-independent evidence of a private destination address is available for this Redis DSN.',
            'Die erfolgreiche Redis-Abfrage nutzte eine private IP-Adresse oder den lokalen Loopback-Endpunkt.' => 'The successful Redis query used a private IP address or the local loopback endpoint.',
            'Die konfigurierte private Redis-Zieladresse konnte nicht erfolgreich abgefragt werden.' => 'The configured private Redis destination could not be queried successfully.',
            'Der Speicherpfad liegt innerhalb des öffentlichen Webpfads.' => 'The storage path is inside the public web path.',
            'Die Dateirechte des privaten Speichers konnten nicht geprüft werden.' => 'Private storage permissions could not be checked.',
            'Der private Speicher oder sein Elternverzeichnis ist für andere Nutzer zu weit freigegeben.' => 'Private storage or its parent directory grants excessive access to other users.',
            'Der private Speicher liegt außerhalb des Webpfads, ist auf den Dienstnutzer begrenzt und wurde erfolgreich geprüft.' => 'Private storage is outside the web path, restricted to the service user, and passed its checks.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'PostgreSQL 18: Unknown.',
            $translator->trans('%capability%: %status%.', ['%capability%' => 'PostgreSQL 18', '%status%' => 'Unknown'], 'messages', 'en'),
        );
    }

    public function testEnglishAdminActivityAreaAndActionLabelsAreTranslated(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Datenbanksicherungen' => 'Database backups',
            'Mitglieder' => 'Members',
            'Videos' => 'Videos',
            'News' => 'News',
            'Serien' => 'Series',
            'Kategorien' => 'Categories',
            'Schlagwörter' => 'Tags',
            'Kommentare' => 'Comments',
            'Kontakt' => 'Contact',
            'Rollen und Rechte' => 'Roles and permissions',
            'Theme und Layout' => 'Theme and layout',
            'Einstellungen' => 'Settings',
            'Seiten' => 'Pages',
            'Aktivität' => 'Activity',
            'Verwaltung' => 'Administration',
            'Datenbanksicherung erstellt' => 'Database backup created',
            'Datenbanksicherung geprüft' => 'Database backup verified',
            'Datenbanksicherung heruntergeladen' => 'Database backup downloaded',
            'Datenbanksicherung gelöscht' => 'Database backup deleted',
            'Mitgliedskonto angelegt' => 'Member account created',
            'Aktivitätsverlauf geleert' => 'Activity history cleared',
            'Passwort-Reset ausgestellt' => 'Password reset issued',
            'Einrichtungscode ausgestellt' => 'Setup code issued',
            'Kontostatus geändert' => 'Account status changed',
            'Eintrag gelöscht' => 'Entry deleted',
            'Eintrag moderiert' => 'Entry moderated',
            'Einreichung freigegeben' => 'Submission approved',
            'Inhalte importiert' => 'Content imported',
            'Website-Einstellungen geändert' => 'Website settings changed',
            'Video- und Inhaltseinstellungen geändert' => 'Video and content settings changed',
            'Verwaltungsrolle geändert' => 'Administration role changed',
            'Theme und Layout geändert' => 'Theme and layout changed',
            'Inhalt angelegt' => 'Content created',
            'Inhalt bearbeitet' => 'Content edited',
            'Verwaltungseintrag geändert' => 'Administration entry changed',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }
    }

    public function testEnglishDatabaseBackupAdministrationMessagesAreTranslated(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Speicherstatus' => 'Storage status',
            'Sicherungen werden verschlüsselt in einem ausdrücklich konfigurierten privaten Speicher außerhalb des Anwendungsordners abgelegt. Wiederherstellungen werden hier nicht ausgeführt.' => 'Backups are encrypted and stored in a specifically configured private location outside the application directory. Restores are not performed here.',
            'Sicherungen sind derzeit deaktiviert: %reason%' => 'Backups are currently disabled: %reason%',
            'Sicherungsdaten konnten nicht sicher gelesen werden.' => 'Backup data could not be read safely.',
            'Der Sicherungsschlüssel ist nicht sicher eingerichtet.' => 'The backup key is not configured securely.',
            'Die erforderlichen PostgreSQL-Sicherungswerkzeuge sind nicht verfügbar.' => 'The required PostgreSQL backup tools are unavailable.',
            'Der private Sicherungsspeicher ist nicht verfügbar.' => 'The private backup storage is unavailable.',
            'Jetzt sichern' => 'Back up now',
            'Vorhandene Sicherungen' => 'Existing backups',
            'Erstellt (UTC)' => 'Created (UTC)',
            'Größe' => 'Size',
            'Aktionen' => 'Actions',
            'Byte' => 'byte',
            'Bytes' => 'bytes',
            'Prüfen' => 'Verify',
            'Herunterladen' => 'Download',
            'Löschen' => 'Delete',
            'Es liegen noch keine Sicherungen vor.' => 'There are no backups yet.',
            'Datenbanksicherung erstellt (%created_at%, %size_bytes% Bytes).' => 'Database backup created (%created_at%, %size_bytes% bytes).',
            'Die Sicherung wurde entschlüsselt, authentifiziert und als PostgreSQL-Archiv geprüft.' => 'The backup was decrypted, authenticated, and verified as a PostgreSQL archive.',
            'Sicherung nicht verfügbar.' => 'Backup unavailable.',
            'Die ausgewählte Sicherung wurde gelöscht.' => 'The selected backup was deleted.',
            'Die Sicherungsaktion konnte nicht sicher ausgeführt werden.' => 'The backup operation could not be completed safely.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'Backups are currently disabled: The backup key is not configured securely.',
            $translator->trans(
                'Sicherungen sind derzeit deaktiviert: %reason%',
                ['%reason%' => $translator->trans('Der Sicherungsschlüssel ist nicht sicher eingerichtet.', [], 'messages', 'en')],
                'messages',
                'en',
            ),
        );
        self::assertSame(
            'Database backup created (2026-10-23T12:00:00Z, 42 bytes).',
            $translator->trans(
                'Datenbanksicherung erstellt (%created_at%, %size_bytes% Bytes).',
                ['%created_at%' => '2026-10-23T12:00:00Z', '%size_bytes%' => 42],
                'messages',
                'en',
            ),
        );
    }

    public function testEnglishStaffRoleAdministrationMessagesAreTranslated(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Mitarbeiterkonten' => 'Staff accounts',
            'Verwaltungszugriff wird pro MFA-geschütztem Mitarbeiterkonto aus einer festen Rollenliste vergeben.' => 'Administration access is assigned to each MFA-protected staff account from a fixed list of roles.',
            '%count% Konten · Seite %page% von %page_count%' => '%count% accounts · page %page% of %page_count%',
            'Verwaltungsrollen' => 'Administration roles',
            'MFA' => 'MFA',
            'Verwaltungszugriff' => 'Administration access',
            'Eingerichtet' => 'Set up',
            'Kein Verwaltungszugriff' => 'No administration access',
            'Moderator für Videos und Kommentare' => 'Video and comment moderator',
            'Videomoderation' => 'Video moderation',
            'Kommentarmoderation' => 'Comment moderation',
            'eigenes Konto' => 'own account',
            'Verwaltungsrolle für %identifier%' => 'Administration role for %identifier%',
            'Speichern' => 'Save',
            'Eigene Rolle geschützt' => 'Your own role is protected',
            'Keine Mitarbeiterkonten gefunden.' => 'No staff accounts found.',
            'Administratoren verwalten die gesamte Website sowie Video- und Kommentarmoderation.' => 'Administrators manage the entire website, including video and comment moderation.',
            'Moderatoren bearbeiten ausschließlich Video- und Kommentarmeldungen.' => 'Moderators handle only video and comment reports.',
            'Video- und Kommentarmoderatoren sehen jeweils nur ihre zuständigen Warteschlangen.' => 'Video and comment moderators see only their assigned queues.',
            'Rollenänderungen invalidieren bestehende Sitzungen des betroffenen Kontos beim nächsten Zugriff.' => 'Role changes invalidate the affected account’s existing sessions on its next request.',
            'Seitennavigation' => 'Pagination',
            'Vorherige' => 'Previous',
            'Nächste' => 'Next',
            'Seite %page% von %page_count%' => 'Page %page% of %page_count%',
            'Die Anfrage enthält unbekannte Rollenfelder.' => 'The request contains unknown role fields.',
            'Bitte wählen Sie eine gültige Verwaltungsrolle.' => 'Choose a valid administration role.',
            'Die gewählte Verwaltungsrolle wird nicht unterstützt.' => 'The selected administration role is not supported.',
            'Die eigene Rolle kann in dieser Ansicht nicht geändert werden.' => 'Your own role cannot be changed from this page.',
            'Das Zielkonto muss zuerst mit MFA eingerichtet werden, bevor Verwaltungszugriff vergeben wird.' => 'The target account must be set up with MFA before administration access can be assigned.',
            'Ihr Konto muss für eine Rollenänderung mit MFA eingerichtet sein.' => 'Your account must be set up with MFA to change roles.',
            'Die Sicherheitsprüfung der Anfrage ist fehlgeschlagen.' => 'The request security check failed.',
            'Der letzte Administrator kann nicht herabgestuft werden.' => 'The last administrator cannot be demoted.',
            'Der Verwaltungsrollenstand ist ungültig und wurde nicht verändert.' => 'The administration role state is invalid and was not changed.',
            'Ihre Administratorberechtigung ist nicht mehr gültig. Melden Sie sich erneut an.' => 'Your administrator permission is no longer valid. Sign in again.',
            'Die Verwaltungsrollen sind derzeit nicht verfügbar.' => 'Administration roles are currently unavailable.',
            'Die Verwaltungsrolle wurde gespeichert. Die bisherige Sitzung des betroffenen Kontos wird beim nächsten Zugriff ungültig.' => 'The administration role was saved. The affected account’s current session will become invalid on its next request.',
            'Die Verwaltungsrolle ist bereits so eingestellt.' => 'This administration role is already set.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            '7 accounts · page 2 of 4',
            $translator->trans('%count% Konten · Seite %page% von %page_count%', ['%count%' => 7, '%page%' => 2, '%page_count%' => 4], 'messages', 'en'),
        );
        self::assertSame(
            'Page 2 of 4',
            $translator->trans('Seite %page% von %page_count%', ['%page%' => 2, '%page_count%' => 4], 'messages', 'en'),
        );
    }

    public function testDefaultLocaleRemainsGermanAndUnknownMessagesUseGermanFallback(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        self::assertSame('de', static::getContainer()->getParameter('kernel.default_locale'));
        self::assertSame(
            'Dieser Text liegt noch nicht im englischen Katalog.',
            $translator->trans('Dieser Text liegt noch nicht im englischen Katalog.', [], 'messages', 'en'),
        );
    }

    public function testPublicVisitorMessagesHaveEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Gaming-News und Videos' => 'Gaming news and videos',
            'Seiten' => 'Pages',
            'Kontakt' => 'Contact',
            'Admin' => 'Administration',
            'Entdecke aktuelle Videos und redaktionelle Beiträge.' => 'Explore the latest videos and editorial articles.',
            'Videos entdecken' => 'Explore videos',
            'Videokatalog' => 'Video catalogue',
            'Neueste Videos' => 'Latest videos',
            'Beliebte Videos' => 'Popular videos',
            'Vorschaubild für %title%' => 'Thumbnail for %title%',
            'Noch keine Videos veröffentlicht.' => 'No videos have been published yet.',
            'Neueste News' => 'Latest news',
            'Noch keine News veröffentlicht.' => 'No news have been published yet.',
            'Mein Profil' => 'My profile',
            'Passwort ändern' => 'Change password',
            'Wiedergabelisten' => 'Playlists',
            'Mitglieder' => 'Members',
            'Datenschutz' => 'Privacy',
            'Notwendige Cookies unterstützen Anmeldung und Sicherheit. Derzeit werden keine Analyse- oder Werbecookies gesetzt.' => 'Essential cookies support sign-in and security. Analytics and advertising cookies are not currently set.',
            'Ihre gespeicherte Auswahl: %choice%.' => 'Your saved choice: %choice%.',
            'Alle optionalen Kategorien erlauben' => 'Allow all optional categories',
            'Nur notwendige Cookies erlauben' => 'Allow essential cookies only',
            'Wir führen Wartungsarbeiten durch' => 'We are performing maintenance',
            'Inhalt nicht gefunden' => 'Content not found',
            'Dieser Inhalt ist nicht verfügbar oder wurde noch nicht veröffentlicht.' => 'This content is unavailable or has not been published yet.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'Thumbnail for Sunrise',
            $translator->trans('Vorschaubild für %title%', ['%title%' => 'Sunrise'], 'messages', 'en'),
        );
    }

    public function testMemberAccountAndRecoveryMessagesHaveEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Mitgliedskonto erstellen' => 'Create a member account',
            'Mitglied anmelden' => 'Member sign-in',
            'Dein Kanal bleibt zunächst privat.' => 'Your channel will remain private initially.',
            '3 bis 32 Zeichen: Buchstaben, Zahlen, Unterstriche oder Bindestriche.' => '3 to 32 characters: letters, numbers, underscores, or hyphens.',
            'Die Anmeldung ist fehlgeschlagen. Prüfe deine Angaben und versuche es erneut.' => 'Sign-in failed. Check your details and try again.',
            'Die Google-Verknüpfung ist derzeit nicht verfügbar.' => 'Google linking is currently unavailable.',
            'Deine E-Mail-Adresse wurde bestätigt. Du kannst dich jetzt anmelden.' => 'Your email address has been confirmed. You can now sign in.',
            'Dieses Google-Konto kann nicht mit diesem Profil verknüpft werden.' => 'This Google account cannot be linked to this profile.',
            'Die Google-Verknüpfung wurde entfernt.' => 'The Google link has been removed.',
            'Bitte gib eine gültige E-Mail-Adresse ein.' => 'Enter a valid email address.',
            'Das aktuelle Passwort ist falsch.' => 'The current password is incorrect.',
            'Der Einrichtungscode ist ungültig oder abgelaufen.' => 'The setup code is invalid or has expired.',
            'Bestätigungs-E-Mail erneut anfordern' => 'Request another confirmation email',
            'Wenn ein passendes Konto besteht, erhältst du eine Nachricht. Prüfe auch deinen Spam-Ordner.' => 'If a matching account exists, you will receive a message. Check your spam folder too.',
            'Der Wiederherstellungslink ist ungültig, abgelaufen oder bereits verwendet.' => 'The recovery link is invalid, expired, or has already been used.',
            'Gib den einmaligen Einrichtungscode ein und lege ein Passwort mit mindestens 12 Zeichen fest.' => 'Enter your one-time setup code and choose a password with at least 12 characters.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'Channel · example-channel',
            $translator->trans('Kanal · %handle%', ['%handle%' => 'example-channel'], 'messages', 'en'),
        );
    }

    public function testMemberSecurityEmailMessagesHaveEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'E-Mail-Adresse für %site_name% bestätigen' => 'Confirm your email address for %site_name%',
            'Öffne diesen Link, um deine E-Mail-Adresse zu bestätigen:' => 'Open this link to confirm your email address:',
            'Der Link läuft nach einer Stunde ab und kann nur einmal verwendet werden.' => 'This link expires after one hour and can only be used once.',
            'Passwort für %site_name% zurücksetzen' => 'Reset your password for %site_name%',
            'Öffne diesen Link, um ein neues Passwort zu setzen:' => 'Open this link to set a new password:',
            'Der Link läuft nach 30 Minuten ab und kann nur einmal verwendet werden. Falls du diese Anfrage nicht gestellt hast, kannst du diese Nachricht ignorieren.' => 'This link expires after 30 minutes and can only be used once. If you did not make this request, you can ignore this message.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'Reset your password for Harmonia Video',
            $translator->trans('Passwort für %site_name% zurücksetzen', ['%site_name%' => 'Harmonia Video'], 'messages', 'en'),
        );
    }

    public function testMemberActivityDirectoryAndPlaylistMessagesHaveEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Hier siehst du deine seit der Kontoverknüpfung eingereichten Kommentare und Videos. Diese Ansicht ist nur für dich sichtbar.' => 'Here you can see comments and videos submitted since your account was linked. This view is private.',
            'Letzte Videoeinreichungen' => 'Recent video submissions',
            'Prüfstatus:' => 'Review status:',
            'Meine Kommentare' => 'My comments',
            'Auf dieser Seite sind noch keine Kommentare vorhanden.' => 'There are no comments on this page yet.',
            'Community' => 'Community',
            'Hier erscheinen nur aktive Mitglieder, die ihren Kanal selbst öffentlich gestellt haben.' => 'Only active members who have made their channel public appear here.',
            'Öffentliche Kanäle suchen' => 'Search public channels',
            'Keine öffentlichen Kanäle gefunden.' => 'No public channels were found.',
            'Öffentliche Kanäle: %channel_count%' => 'Public channels: %channel_count%',
            'Neue Wiedergabeliste' => 'New playlist',
            'Öffentliche Listen erscheinen nur, wenn auch dein Kanal öffentlich ist. Nicht veröffentlichte Videos bleiben aus öffentlichen Listen ausgeblendet.' => 'Public playlists appear only while your channel is public. Unpublished videos remain hidden from public playlists.',
            'Bitte wähle eine deiner Wiedergabelisten.' => 'Choose one of your playlists.',
            'Das Video ist bereits in dieser Wiedergabeliste.' => 'This video is already in this playlist.',
            'Die Wiedergabeliste wurde gelöscht.' => 'The playlist has been deleted.',
            'Wartet auf Prüfung' => 'Pending review',
            'Wartet auf Freigabe' => 'Pending approval',
            'Freigegeben' => 'Approved',
            'Gemeldet' => 'Reported',
            'Abgelehnt' => 'Rejected',
            'Diese Wiedergabeliste enthält noch keine veröffentlichten Videos.' => 'This playlist does not contain any published videos yet.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'Page 2 of 7',
            $translator->trans('Seite %page% von %total%', ['%page%' => 2, '%total%' => 7], 'messages', 'en'),
        );
    }

    public function testPublicVideoNewsAndCommentMessagesHaveEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Veröffentlichte Videos nach datensparsam gezählten Aufrufen.' => 'Published videos ranked using privacy-friendly view counts.',
            'Neu veröffentlichte Videos aus allen Kategorien.' => 'Recently published videos from every category.',
            'Alle Kategorien' => 'All categories',
            'News und Artikel' => 'News and articles',
            'Aktuelle Beiträge aus der Redaktion.' => 'Recent stories from the editorial team.',
            'Keine Beiträge mit diesen Filtern gefunden.' => 'No stories matched these filters.',
            'Live-Stream wird vorbereitet.' => 'Preparing live stream.',
            'Dieser Live-Stream ist nicht verfügbar.' => 'This live stream is unavailable.',
            'Der Live-Stream ist derzeit nicht verfügbar. Bitte versuche es später erneut.' => 'This live stream is currently unavailable. Please try again later.',
            'Live-Stream bereit. Drücke Wiedergabe.' => 'Live stream ready. Press play.',
            'Dieser Browser unterstützt die Live-Wiedergabe nicht. Nutze den Link zum Stream.' => 'This browser does not support live playback. Use the link to the stream.',
            'Dieser Stream ist derzeit nicht verfügbar oder für diese Website nicht freigegeben.' => 'This stream is currently unavailable or is not approved for this website.',
            'Videoquelle: %source%' => 'Video source: %source%',
            'In Wiedergabeliste speichern' => 'Save to playlist',
            'Video oder Quelle funktioniert nicht' => 'Video or source is not working',
            'Unangemessener Inhalt' => 'Inappropriate content',
            'Urheberrecht oder Rechte' => 'Copyright or rights issue',
            'Irreführende Angaben' => 'Misleading information',
            'Sonstiger Grund' => 'Other reason',
            'Bitte wähle einen gültigen Grund und gib höchstens 1000 Zeichen Zusatzinformation ein.' => 'Choose a valid reason and provide no more than 1,000 characters of additional details.',
            'Dein Kommentar wurde zur Prüfung eingereicht.' => 'Your comment has been submitted for review.',
            'Der Kommentar muss zwischen 5 und 3000 Zeichen lang sein.' => 'The comment must be between 5 and 3,000 characters.',
            'Zurück zu allen Videos' => 'Back to all videos',
            'Sitemap' => 'Sitemap',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'Video source: YouTube',
            $translator->trans('Videoquelle: %source%', ['%source%' => 'YouTube'], 'messages', 'en'),
        );
    }

    public function testPublicContactSearchAndInformationMessagesHaveEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Wir helfen gern' => 'We are happy to help',
            'Deine Nachricht wird zur Bearbeitung in der Verwaltung gespeichert. Bitte sende hier keine sensiblen Daten.' => 'Your message will be stored in the administration area for processing. Do not send sensitive information here.',
            'Betreff' => 'Subject',
            'Nachricht senden' => 'Send message',
            'Durchsuche die aktuell freigegebenen Inhaltsbereiche.' => 'Search the content areas currently available to the public.',
            'Suchbegriff' => 'Search term',
            'Keine Serien gefunden.' => 'No series were found.',
            'Keine News gefunden.' => 'No news articles were found.',
            'Keine Videos gefunden.' => 'No videos were found.',
            'Gib einen Suchbegriff ein, um veröffentlichte Inhalte zu finden.' => 'Enter a search term to find published content.',
            'Informationen und Hinweise zur Website.' => 'Information and guidance about this website.',
            'Noch keine Seiten veröffentlicht.' => 'No pages have been published yet.',
            'Zurück zu allen Seiten' => 'Back to all pages',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'Page 3',
            $translator->trans('Seite %page%', ['%page%' => 3], 'messages', 'en'),
        );
    }

    public function testSeriesEpisodeAndVideoSubmissionMessagesHaveEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Redaktionelle Reihen und ihre Folgen.' => 'Editorial series and their episodes.',
            'Alle Genres' => 'All genres',
            'Noch keine Serien veröffentlicht.' => 'No series have been published yet.',
            'Für diese Serie sind noch keine veröffentlichten Folgen vorhanden.' => 'This series does not have any published episodes yet.',
            'Dein Browser kann dieses Video nicht abspielen.' => 'Your browser cannot play this video.',
            'Videodetails' => 'Video details',
            'Eingereichte Videos bleiben privat, bis ein Administrator sie geprüft und freigegeben hat.' => 'Submitted videos remain private until an administrator has reviewed and approved them.',
            'Videodatei (MP4, WebM oder Ogg, höchstens 32 MiB)' => 'Video file (MP4, WebM, or Ogg, up to 32 MiB)',
            'Zur Prüfung einreichen' => 'Submit for review',
            'Dateien werden privat gespeichert. Das Video erscheint erst nach einer redaktionellen Freigabe.' => 'Files are stored privately. The video will appear only after editorial approval.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'Season 2 · Episode 4',
            $translator->trans('Staffel %season% · Folge %episode%', ['%season%' => 2, '%episode%' => 4], 'messages', 'en'),
        );
        self::assertSame(
            'Video source: Vimeo',
            $translator->trans('Videoquelle: %host%', ['%host%' => 'Vimeo'], 'messages', 'en'),
        );
    }

    public function testSeriesAndEpisodeAdministrationHasEnglishTranslations(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Serien anlegen und veröffentlichte Folgen verwalten.' => 'Create series and manage published episodes.',
            'Serienliste' => 'Series list',
            'Alle Serien' => 'All series',
            'Neue Serie' => 'New series',
            'URL-Name' => 'URL slug',
            'Serie bearbeiten' => 'Edit series',
            'Serie anlegen' => 'Create series',
            'Titel oder Beschreibung' => 'Title or description',
            'Entwürfe' => 'Drafts',
            'Serie' => 'Series',
            'Enthält Folgen' => 'Contains episodes',
            'Noch keine Serien vorhanden.' => 'There are no series yet.',
            'Folgen verweisen auf vorhandene Videos und besitzen einen eigenen Sichtbarkeitsstatus.' => 'Episodes link to existing videos and have their own visibility status.',
            'Bitte prüfe die Eingaben.' => 'Check the entered values.',
            'Seriendaten' => 'Series details',
            'Kommagetrennt oder je Genre eine Zeile; höchstens 12 Einträge mit jeweils 40 Zeichen. Neue Namen werden im Genrekatalog angelegt.' => 'Separate entries with commas or put each genre on a new line. Up to 12 entries of 40 characters each are allowed. New names are added to the genre catalogue.',
            'Serienbild' => 'Series artwork',
            'Aktuelles Serienbild für %title%' => 'Current series artwork for %title%',
            'Serienbild hochladen' => 'Upload series artwork',
            'Serienbild entfernen' => 'Remove series artwork',
            'Serienbild wurde entfernt.' => 'The series artwork was removed.',
            'Lege Genres unabhängig von Serien an, oder verwalte bestehende Zuordnungen für alle Serien.' => 'Create genres independently of series or manage existing assignments across all series.',
            'Neues Genre' => 'New genre',
            'Genre anlegen' => 'Create genre',
            'Umbenennen' => 'Rename',
            'Noch keine Seriengenres vorhanden.' => 'There are no series genres yet.',
            'Genres im Katalog: %count%' => 'Genres in the catalogue: %count%',
            'Neuer Name für %name%' => 'New name for %name%',
            'Entfernen bestätigen' => 'Confirm removal',
            'Genreseiten' => 'Genre pages',
            'Videos zu Staffeln und Folgen zuordnen. Öffentlich erscheinen nur veröffentlichte Folgen zu veröffentlichten Serien und Videos.' => 'Assign videos to seasons and episodes. Only published episodes of published series and videos appear publicly.',
            'Folgenliste' => 'Episode list',
            'Folge' => 'Episode',
            'Folge zuordnen' => 'Assign episode',
            'Folge bearbeiten' => 'Edit episode',
            'Zuordnen' => 'Assign',
            'Serienfolgen' => 'Series episodes',
            'Serie oder Videotitel' => 'Series or video title',
            'Staffel / Folge' => 'Season / episode',
            'Video: %status%' => 'Video: %status%',
            'Zuordnung entfernen' => 'Remove assignment',
            'Noch keine Folgen zugeordnet.' => 'No episodes have been assigned yet.',
            'Eine Folge verweist auf ein Video und kann eigene Metadaten sowie einen Sichtbarkeitsstatus erhalten.' => 'An episode links to a video and can have its own metadata and visibility status.',
            'Folgendaten' => 'Episode details',
            'Bis zu 1.000 Videos werden zur Auswahl geladen.' => 'Up to 1,000 videos are loaded for selection.',
            'Staffel' => 'Season',
            'Folgentitel (optional)' => 'Episode title (optional)',
            'Leer lassen, um den Videotitel zu verwenden.' => 'Leave blank to use the video title.',
            'Folgenbeschreibung (optional)' => 'Episode description (optional)',
            'Leer lassen, um die Videobeschreibung zu verwenden.' => 'Leave blank to use the video description.',
            'Sichtbarkeit der Folge' => 'Episode visibility',
            'Serie wurde nicht gefunden.' => 'The series could not be found.',
            'Der Serientitel ist erforderlich und darf höchstens 180 Zeichen lang sein.' => 'A series title is required and must be no more than 180 characters.',
            'Der URL-Name darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten.' => 'The URL slug may contain only lowercase letters, numbers, and hyphens.',
            'Die Serienbeschreibung darf höchstens 50.000 Zeichen lang sein.' => 'The series description must be no more than 50,000 characters.',
            'Für diesen URL-Namen gibt es bereits eine Serie.' => 'A series already uses this URL slug.',
            'Ein Genre oder der URL-Name wurde inzwischen bereits vergeben. Bitte lade das Formular neu.' => 'A genre or URL slug has just been claimed. Please reload the form.',
            'Serie wurde gespeichert.' => 'The series was saved.',
            'Die Serie enthält noch Folgen und kann nicht gelöscht werden.' => 'This series still contains episodes and cannot be deleted.',
            'Die Serie wurde gelöscht, aber die private Bilddatei konnte nicht bereinigt werden.' => 'The series was deleted, but its private artwork file could not be cleaned up.',
            'Serie wurde gelöscht.' => 'The series was deleted.',
            'Folge wurde nicht gefunden.' => 'The episode could not be found.',
            'Bitte wähle eine vorhandene Serie.' => 'Select an existing series.',
            'Bitte wähle ein vorhandenes Video.' => 'Select an existing video.',
            'Die Staffelnummer muss zwischen 1 und 999 liegen.' => 'The season number must be between 1 and 999.',
            'Die Folgenummer muss zwischen 1 und 999 liegen.' => 'The episode number must be between 1 and 999.',
            'Der Folgen-Titel darf höchstens 180 Zeichen lang sein.' => 'The episode title must be no more than 180 characters.',
            'Die Folgen-Beschreibung darf höchstens 20.000 Zeichen lang sein.' => 'The episode description must be no more than 20,000 characters.',
            'Folgen-Metadaten dürfen keine unzulässigen Steuerzeichen enthalten.' => 'Episode metadata must not contain prohibited control characters.',
            'Diese Staffel- und Folgenummer ist bereits belegt.' => 'This season and episode number are already in use.',
            'Dieses Video ist bereits einer Folge zugeordnet.' => 'This video is already assigned to an episode.',
            'Folge wurde gespeichert.' => 'The episode was saved.',
            'Folgenzuordnung wurde entfernt.' => 'The episode assignment was removed.',
            'Das Genre ist bereits vorhanden.' => 'This genre already exists.',
            'Das Genre wurde nicht gefunden.' => 'The genre could not be found.',
            'Das neue Genre ist bereits vorhanden.' => 'The new genre name already exists.',
            'Bitte genau einen gültigen Genrenamen angeben.' => 'Provide exactly one valid genre name.',
            'Das Genre wurde inzwischen bereits angelegt.' => 'This genre has just been created.',
            'Das neue Genre wurde inzwischen bereits vergeben.' => 'The new genre name has just been claimed.',
            'Seriengenre wurde angelegt.' => 'The series genre was created.',
            'Genre umbenannt; %count% Serien verwenden es.' => 'The genre was renamed and is used by %count% series.',
            'Bestätige das Entfernen des Genres aus allen Serien.' => 'Confirm removing this genre from all series.',
            'Genre aus %count% Serien entfernt.' => 'The genre was removed from %count% series.',
            'Beim Entfernen darf keine Datei mitgesendet werden.' => 'Do not attach a file when removing artwork.',
            'Für diese Serie ist kein Bild gespeichert.' => 'This series has no saved artwork.',
            'Das Serienbild wurde entfernt, die private Datei konnte aber nicht bereinigt werden.' => 'The series artwork was removed, but its private file could not be cleaned up.',
            'Bitte wähle eine gültige Serienbildaktion aus.' => 'Choose a valid series artwork action.',
            'Bitte wähle eine gültige Bilddatei aus.' => 'Choose a valid image file.',
            'Der sichere private Dateispeicher ist derzeit nicht verfügbar; der Upload wurde gesperrt.' => 'Secure private file storage is currently unavailable, so the upload was blocked.',
            'Ein neuer privater Upload konnte nach dem Speicherfehler nicht bereinigt werden.' => 'A new private upload could not be cleaned up after the storage error.',
            'Das frühere private Serienbild konnte nicht bereinigt werden.' => 'The previous private series artwork could not be cleaned up.',
            'Serienbild wurde gespeichert.' => 'The series artwork was saved.',
            'Die Genre-Liste muss gültiges UTF-8 enthalten.' => 'The genre list must contain valid UTF-8 text.',
            'Die Genre-Liste darf höchstens 600 Zeichen lang sein.' => 'The genre list must be no more than 600 characters.',
            'Die Genre-Liste enthält unzulässige Steuerzeichen.' => 'The genre list contains prohibited control characters.',
            'Ein Genre darf höchstens 40 Zeichen lang sein.' => 'A genre name must be no more than 40 characters.',
            'Pro Serie sind höchstens 12 Genres erlaubt.' => 'A series can have at most 12 genres.',
            'Das Serienbild konnte nicht sicher gelesen werden.' => 'The series artwork could not be read safely.',
            'Serienbilder müssen zwischen 1 Byte und 4 MiB groß sein.' => 'Series artwork must be between 1 byte and 4 MiB.',
            'Das Serienbild ist leer, zu groß oder wurde während der Prüfung verändert.' => 'The series artwork is empty, too large, or changed while it was being checked.',
            'Erlaubt sind ausschließlich JPEG-, PNG- und WebP-Bilder.' => 'Only JPEG, PNG, and WebP images are allowed.',
            'Das Bildformat oder die Bildabmessungen sind ungültig.' => 'The image format or dimensions are invalid.',
            'Die Kennung des privaten Serienbilds ist ungültig.' => 'The private series artwork identifier is invalid.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'The genre was renamed and is used by 3 series.',
            $translator->trans('Genre umbenannt; %count% Serien verwenden es.', ['%count%' => 3], 'messages', 'en'),
        );
        self::assertSame(
            'Current series artwork for Sunrise',
            $translator->trans('Aktuelles Serienbild für %title%', ['%title%' => 'Sunrise'], 'messages', 'en'),
        );
    }

    public function testAdministrationDashboardActivityAndCategoryMessagesTranslate(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'CMS-Verwaltung' => 'CMS administration',
            'Inhalte, Freigaben und Systemstatus auf einen Blick.' => 'Content, approvals, and system status at a glance.',
            'Warteschlange öffnen' => 'Open queue',
            'Die B/C-Kontrollmatrix enthält Anforderungen, aber keinen Laufzeitnachweis. Ein unabhängiger Attestor, aktive B→C-Erkennung und unabhängige Alarmierung sind derzeit nicht eingerichtet.' => 'The B/C control matrix defines requirements but has no runtime evidence. An independent attestor, active B-to-C detection, and independent alerting are not currently configured.',
            'Erfolgreiche Änderungen im Verwaltungsbereich. Inhalte aus Formularen, Netzwerkadressen und Geheimnisse werden nicht gespeichert.' => 'Successful changes in the administration area. Form contents, network addresses, and secrets are not stored.',
            'Der Aktivitätsverlauf ist leer.' => 'The activity history is empty.',
            'Entfernter Administrator' => 'Removed administrator',
            'Kategorien für redaktionelle Beiträge und Videos pflegen.' => 'Manage categories for editorial stories and videos.',
            'Noch keine Kategorien vorhanden.' => 'No categories exist yet.',
            'Eine Kategorie gehört zu News oder Videos. Verwendete Kategorien können nicht gelöscht werden.' => 'A category belongs to news or videos. Categories in use cannot be deleted.',
            'Der Kategoriename ist erforderlich und darf höchstens 100 Zeichen lang sein.' => 'A category name is required and must be no more than 100 characters.',
            'Bitte wähle News oder Video als Inhaltsbereich.' => 'Choose news or video as the content area.',
            'Diese Kategorie gibt es in diesem Inhaltsbereich bereits.' => 'This category already exists in this content area.',
            'Kategorie wurde angelegt.' => 'The category was created.',
            'Kategorie wurde gespeichert.' => 'The category was saved.',
            'Kategorie wurde nicht gefunden.' => 'The category could not be found.',
            'Die Kategorie wird noch verwendet und kann nicht gelöscht werden.' => 'This category is still in use and cannot be deleted.',
            'Kategorie wurde gelöscht.' => 'The category was deleted.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'Approve 2 comments, review 3 reports',
            $translator->trans('%pending% Kommentare freigeben, %reported% Meldungen bearbeiten', ['%pending%' => 2, '%reported%' => 3], 'messages', 'en'),
        );
        self::assertSame(
            'Review 4 video submissions',
            $translator->trans('%count% Videoeinreichungen prüfen', ['%count%' => 4], 'messages', 'en'),
        );
        self::assertSame(
            '3 results in this view',
            $translator->trans('Treffer in dieser Ansicht: %result_count%', ['%result_count%' => 3], 'messages', 'en'),
        );
    }

    public function testAdministrationSignInAndAuthorizationMessagesTranslate(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Private Plattform' => 'Private platform',
            'Verwaltungszugang' => 'Administration sign-in',
            'Melde dich mit einem Administratorkonto an, um die Verwaltung zu öffnen.' => 'Sign in with an administrator account to open the administration area.',
            'Authenticator- oder Wiederherstellungscode' => 'Authenticator or recovery code',
            'Zugriff verweigert' => 'Access denied',
            'Für diesen Bereich ist ein berechtigtes Administratorkonto erforderlich.' => 'An authorized administrator account is required for this area.',
            'Zur Plattformübersicht' => 'Back to platform overview',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }
    }

    public function testAdministrationContactAndCommentModerationMessagesTranslate(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Nachrichten werden nur hier angezeigt. Erledigte Nachrichten können gelöscht werden.' => 'Messages are only shown here. Completed messages can be deleted.',
            'Name, E-Mail, Betreff oder Text' => 'Name, email, subject, or message',
            'Kontaktnachrichten' => 'Contact messages',
            'Keine Kontaktnachrichten gefunden.' => 'No contact messages found.',
            'Kommentarprüfung' => 'Comment moderation',
            'Kommentare prüfen' => 'Review comments',
            'Neue Kommentare bleiben verborgen, bis sie freigegeben werden. Gemeldete Beiträge stehen zur erneuten Prüfung bereit.' => 'New comments stay hidden until approved. Reported comments are ready for another review.',
            'Eingereichte Kommentare' => 'Submitted comments',
            'Die Liste zeigt höchstens 100 Treffer. Kommentartext wird als reiner Text ausgegeben.' => 'The list shows at most 100 results. Comment text is displayed as plain text.',
            'Keine Kommentare für diese Auswahl gefunden.' => 'No comments matched this selection.',
            'Kommentar wurde freigegeben.' => 'Comment approved.',
            'Kommentar wurde abgelehnt.' => 'Comment rejected.',
            'Meldungen wurden zurückgesetzt.' => 'Reports cleared.',
            'Kommentar wurde gelöscht.' => 'Comment deleted.',
            'Die gewählte Aktion ist ungültig.' => 'The selected action is invalid.',
            'Kommentar wurde nicht gefunden.' => 'Comment was not found.',
            'Kommentar-Wortfilter' => 'Comment word filter',
            'Treffer bleiben verborgen und warten auf eine manuelle Freigabe. Der Filter lehnt Kommentare nicht automatisch ab.' => 'Matches stay hidden until manually approved. The filter does not automatically reject comments.',
            'Wortgruppen werden mit normalisierten Leerzeichen und ohne Beachtung der Großschreibung verglichen.' => 'Phrases are compared using normalized spaces and case-insensitive matching.',
            'Noch keine Filterwörter eingetragen.' => 'No filter terms have been added yet.',
            'Filterwort wurde hinzugefügt.' => 'Filter term added.',
            'Filterwort wurde entfernt.' => 'Filter term removed.',
            'Filterwort wurde nicht gefunden.' => 'Filter term was not found.',
            'Das Filterwort darf nicht leer sein.' => 'The filter term must not be empty.',
            'Ein Filterwort oder eine Wortgruppe darf höchstens 100 Zeichen lang sein.' => 'A filter term or phrase must be no more than 100 characters long.',
            'Der Text muss gültiges UTF-8 enthalten.' => 'The text must contain valid UTF-8.',
            'Der Text enthält unzulässige Steuerzeichen.' => 'The text contains disallowed control characters.',
            'Die Liste ist auf 500 Filterwörter oder Wortgruppen begrenzt.' => 'The list is limited to 500 filter terms or phrases.',
            'Dieses Filterwort oder diese Wortgruppe ist bereits vorhanden.' => 'This filter term or phrase already exists.',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'Word filter: spam',
            $translator->trans('Wortfilter: %term%', ['%term%' => 'spam'], 'messages', 'en'),
        );
        self::assertSame(
            'Reports: 2',
            $translator->trans('Meldungen: %report_count%', ['%report_count%' => 2], 'messages', 'en'),
        );
        self::assertSame(
            '3 of 20 entries',
            $translator->trans('%count% von %max% Einträgen', ['%count%' => 3, '%max%' => 20], 'messages', 'en'),
        );
        self::assertSame(
            'Reports: 2',
            $translator->trans('Meldungen: %report_count%', ['%report_count%' => 2], 'messages', 'en'),
        );
        self::assertSame(
            'Meldungen: 2',
            $translator->trans('Meldungen: %report_count%', ['%report_count%' => 2], 'messages', 'de'),
        );
        self::assertSame(
            '3 results in this view',
            $translator->trans('Treffer in dieser Ansicht: %result_count%', ['%result_count%' => 3], 'messages', 'en'),
        );
        self::assertSame(
            'Public channels: 12',
            $translator->trans('Öffentliche Kanäle: %channel_count%', ['%channel_count%' => 12], 'messages', 'en'),
        );
    }

    public function testAdministrationVideoCatalogAndModerationMessagesTranslate(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Videoquellen erfassen, Metadaten pflegen und den Veröffentlichungsstatus setzen.' => 'Manage video sources and metadata and set publication status.',
            'Anbieter-Import' => 'Provider import',
            'CSV-Import' => 'CSV import',
            'Titel oder Kategorie' => 'Title or category',
            'Alle Schlagwörter' => 'All tags',
            'Sortierung' => 'Sort by',
            'Änderungsdatum' => 'Last modified',
            'Reihenfolge' => 'Order',
            'Absteigend' => 'Descending',
            'Aufsteigend' => 'Ascending',
            'Auswahlaktion' => 'Bulk action',
            'Veröffentlichen' => 'Publish',
            'Als Entwurf speichern' => 'Save as draft',
            'Hervorheben' => 'Feature',
            'Hervorhebung entfernen' => 'Remove featured status',
            'In Kategorie verschieben' => 'Move to category',
            'Ausgewählte löschen' => 'Delete selected',
            'Zielkategorie (nur beim Verschieben)' => 'Target category (when moving only)',
            'Ohne Kategorie' => 'Uncategorized',
            'Löschen bestätigen' => 'Confirm deletion',
            'Ich bestätige das Löschen' => 'I confirm the deletion',
            'Auf Auswahl anwenden' => 'Apply to selection',
            'Wähle bis zu 50 Einträge aus. Löschen entfernt auch ihre Kommentare.' => 'Select up to 50 entries. Deleting an entry also deletes its comments.',
            'Auswahl' => 'Selection',
            'Quelle' => 'Source',
            'Zuletzt geändert' => 'Last modified',
            'Video auswählen: %title%' => 'Select video: %title%',
            'Noch keine Videos. Füge die erste Videoquelle hinzu.' => 'There are no videos yet. Add the first video source.',
            'Verwaltungsseiten' => 'Administration pages',
            'Eingehende Meldungen prüfen und Videos bei Bedarf aus der öffentlichen Ausgabe nehmen.' => 'Review incoming reports and remove videos from public pages when needed.',
            'Zum Videokatalog' => 'Back to the video catalogue',
            'Meldungen' => 'Reports',
            'Es werden höchstens 100 passende Meldungen angezeigt.' => 'At most 100 matching reports are shown.',
            'Zusatzangaben' => 'Additional details',
            'Status und Aktion' => 'Status and action',
            'Video ausblenden' => 'Hide video',
            'Keine Videomeldungen für diesen Filter.' => 'No video reports match this filter.',
            'Neue Dateien bleiben privat, bis du sie freigibst.' => 'New files stay private until you approve them.',
            'Warteschlange' => 'Queue',
            'Es werden höchstens 100 wartende Einreichungen angezeigt.' => 'At most 100 pending submissions are shown.',
            'Vorschau für %title%' => 'Preview for %title%',
            'Video freigeben' => 'Approve video',
            'Keine wartenden Videoeinreichungen.' => 'There are no pending video submissions.',
            'Video-URL' => 'Video URL',
            'Stream' => 'Stream',
            'Einbettung' => 'Embed',
            'Datei-Upload' => 'File upload',
            'Quellentyp' => 'Source type',
            'Alle Quellentypen' => 'All source types',
            'Streams' => 'Streams',
            'Einbettungen' => 'Embeds',
            'Entwurf' => 'Draft',
            'Veröffentlicht' => 'Published',
            'Video oder Quelle funktioniert nicht' => 'Video or source is not working',
            'Unangemessener Inhalt' => 'Inappropriate content',
            'Urheberrecht oder Rechte' => 'Copyright or rights issue',
            'Irreführende Angaben' => 'Misleading information',
            'Sonstiger Grund' => 'Other reason',
            'Offen' => 'Open',
            'Erledigt' => 'Completed',
            'Video ausgeblendet' => 'Video hidden',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            '25 results · page 2 of 4',
            $translator->trans('%total% Treffer · Seite %page% von %page_count%', ['%total%' => 25, '%page%' => 2, '%page_count%' => 4], 'messages', 'en'),
        );
        self::assertSame(
            'Page 2 of 4',
            $translator->trans('Seite %page% von %page_count%', ['%page%' => 2, '%page_count%' => 4], 'messages', 'en'),
        );
        self::assertSame(
            'Reviewed on 23.10.2026 14:30',
            $translator->trans('Bearbeitet am %date%', ['%date%' => '23.10.2026 14:30'], 'messages', 'en'),
        );
        self::assertSame(
            'Select video: Sample title',
            $translator->trans('Video auswählen: %title%', ['%title%' => 'Sample title'], 'messages', 'en'),
        );
    }

    public function testAdministrationVideoFormsAndImportsTranslate(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $messages = [
            'Video bearbeiten' => 'Edit video',
            'Hinzufügen' => 'Add',
            'Geprüfte HTTPS-Quelle oder private Videodatei mit Metadaten speichern.' => 'Save video metadata with a verified HTTPS source or a private video file.',
            'Videodaten' => 'Video details',
            'Dateien werden außerhalb des öffentlichen Webpfads gespeichert; nur MP4, WebM und Ogg bis 32 MiB sind zugelassen.' => 'Files are stored outside the public web path. Only MP4, WebM, and Ogg files up to 32 MiB are accepted.',
            'Quellart' => 'Source type',
            'Einbettungs-URL' => 'Embed URL',
            'Private Videodatei' => 'Private video file',
            'Quell-URL' => 'Source URL',
            'Für Einbettungen sind nur geprüfte YouTube- und Vimeo-Video-URLs zugelassen; fremder HTML-Code wird nicht ausgeführt.' => 'Embeds accept verified YouTube and Vimeo video URLs only. Third-party HTML is never executed.',
            'Unterstützt werden direkte MP4-, M4V-, WebM- und Ogg-Dateien sowie HLS-Live-Playlists (M3U8) auf freigegebenen HTTPS-Hosts. HLS-Segmente und Schlüssel müssen ebenfalls von freigegebenen Hosts kommen; der Server ruft die Stream-Adresse nicht ab.' => 'Direct MP4, M4V, WebM, and Ogg files are supported, along with HLS live playlists (M3U8) on approved HTTPS hosts. HLS segments and keys must also come from approved hosts; the server does not fetch the stream URL.',
            'Die vollständige Adresse einschließlich Abfrageparametern wird öffentlich ausgegeben. Bitte keine Zugangsdaten oder geheimen Tokens verwenden.' => 'The complete address, including query parameters, is shown publicly. Do not include credentials or secret tokens.',
            'Streamhosts sind derzeit nicht freigegeben; bitte die Hostingverwaltung um Einrichtung bitten.' => 'Stream hosts are not approved yet. Ask an administrator to configure them in hosting settings.',
            'Videodatei' => 'Video file',
            'Bei Quellart „Private Videodatei“ erforderlich; beim Bearbeiten optional, wenn die vorhandene Datei bleiben soll.' => 'Required for the private video file source type. When editing, leave this empty to keep the existing file.',
            'z. B. Kultur, Interviews, Neuigkeiten' => 'e.g. culture, interviews, news',
            'Bis zu zehn Schlagwörter, getrennt durch Komma oder Zeilenumbruch.' => 'Enter up to ten tags, separated by commas or line breaks.',
            'Auf der Startseite hervorheben' => 'Feature on the home page',
            'Kommentare erlauben' => 'Allow comments',
            'Vorschaubild' => 'Thumbnail',
            'JPEG, PNG oder WebP bis 4 MiB. SVG-Dateien sind nicht zugelassen; die Bilddatei bleibt außerhalb des öffentlichen Webpfads.' => 'JPEG, PNG, or WebP up to 4 MiB. SVG files are not accepted; the image is stored outside the public web path.',
            'Bilddatei' => 'Image file',
            'Vorschaubild hochladen' => 'Upload thumbnail',
            'Vorschaubild entfernen' => 'Remove thumbnail',
            'Video-CSV-Import' => 'Video CSV import',
            'Video-Metadaten importieren' => 'Import video metadata',
            'Die CSV wird nur im temporären Uploadbereich gelesen und nach dem Request verworfen. Externe URLs werden nicht abgerufen.' => 'The CSV is read only from temporary upload storage and discarded after the request. External URLs are not fetched.',
            'Zurück zu Videos' => 'Back to videos',
            'Der Import wurde nicht gespeichert.' => 'The import was not saved.',
            'CSV-Datei' => 'CSV file',
            'Maximal 2 MiB und 500 Zeilen. Alle importierten Videos werden als Entwürfe angelegt.' => 'Maximum 2 MiB and 500 rows. All imported videos are saved as drafts.',
            'Erforderliche Kopfzeile:' => 'Required header row:',
            'Spaltenreihenfolge: Titel, Beschreibung, Quellart (url, stream, embed), HTTPS-Quell-URL, vorhandene Video-Kategorie oder leer, Schlagwörter mit Komma getrennt. Textfelder mit Kommas müssen CSV-gerecht in Anführungszeichen stehen.' => 'Column order: title, description, source type (url, stream, embed), HTTPS source URL, existing video category or empty, tags separated by commas. Text fields containing commas must be quoted according to CSV format.',
            'CSV prüfen und Entwürfe importieren' => 'Validate CSV and import drafts',
            'Videoanbieter-Import' => 'Video provider import',
            'Die Anfrage enthält ungültige oder unbekannte Importfelder.' => 'The request contains invalid or unknown provider import fields.',
            'Anbieter-Suche und Kanalimport' => 'Provider search and channel import',
            'Ein Abruf liefert höchstens 25 einbettbare Videos. Jeder Treffer wird als Entwurf gespeichert und muss vor der Veröffentlichung geprüft werden.' => 'Each request returns at most 25 embeddable videos. Every result is saved as a draft and must be reviewed before publication.',
            'Der YouTube-Import ist deaktiviert. Auf dem Server muss der API-Schlüssel gesetzt sein. Der Schlüssel wird nicht im Formular oder Browser gespeichert.' => 'YouTube import is disabled. The API key must be configured on the server. It is not stored in the form or browser.',
            'Der Vimeo-Import ist deaktiviert. Auf dem Server muss der Zugriffstoken gesetzt sein. Der Zugriffstoken wird nicht im Formular oder Browser gespeichert.' => 'Vimeo import is disabled. The access token must be configured on the server. It is not stored in the form or browser.',
            'Videos bei einem Anbieter finden' => 'Find videos from a provider',
            'nicht eingerichtet' => 'not configured',
            'Importart' => 'Import type',
            'Nach Suchbegriff' => 'By search term',
            'Nach Kanal-ID' => 'By channel ID',
            'YouTube-Kanal-ID' => 'YouTube channel ID',
            'Vimeo-Nutzer-ID' => 'Vimeo user ID',
            'Nur die YouTube-Kanal-ID im Format UC… wird akzeptiert.' => 'Only YouTube channel IDs in the UC… format are accepted.',
            'Nur eine numerische Vimeo-Nutzer-ID wird akzeptiert; importiert werden die öffentlich einbettbaren Videos dieses Nutzerkanals.' => 'Only numeric Vimeo user IDs are accepted. Publicly embeddable videos from that user\'s channel are imported.',
            'Videokategorie (optional)' => 'Video category (optional)',
            'Suchanfragen und Kanal-IDs werden nur an die feste API des ausgewählten Anbieters gesendet. Bestehende Videos und wiederholte Treffer werden übersprungen.' => 'Search terms and channel IDs are sent only to the selected provider\'s fixed API. Existing videos and duplicate results are skipped.',
            'Treffer als Entwürfe importieren' => 'Import results as drafts',
        ];

        foreach ($messages as $source => $expected) {
            self::assertSame($expected, $translator->trans($source, [], 'messages', 'en'), $source);
        }

        self::assertSame(
            'Current private file: video/webm.',
            $translator->trans('Aktuelle private Datei: %media_type%.', ['%media_type%' => 'video/webm'], 'messages', 'en'),
        );
        self::assertSame(
            'Current thumbnail for Sample video',
            $translator->trans('Aktuelles Vorschaubild für %title%', ['%title%' => 'Sample video'], 'messages', 'en'),
        );
    }
}
