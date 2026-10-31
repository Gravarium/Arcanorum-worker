<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030140000 extends AbstractMigration
{
    private const string OLD_DEFAULT_CONSENT_MESSAGE = 'Wir verwenden notwendige Cookies für Anmeldung und Sicherheit. Derzeit setzen wir keine Analyse- oder Werbecookies.';
    private const string LEGACY_ENTITY_DEFAULT_CONSENT_MESSAGE = 'Wir verwenden notwendige Cookies für Anmeldung, Sicherheit und die Ansichtspräferenz in diesem Browser. Derzeit setzen wir keine Analyse- oder Werbecookies.';
    private const string NEW_DEFAULT_CONSENT_MESSAGE = 'Wir verwenden notwendige Cookies für Anmeldung, Sicherheit und die Ansichtspräferenz in diesem Browser. Optionale Analyse- und Werbedienste werden nur nach Ihrer Zustimmung geladen.';

    public function getDescription(): string
    {
        return 'Ergänzt die optionale, einwilligungsgesteuerte GA4-Websiteanalyse.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD google_analytics_measurement_id VARCHAR(12) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_google_analytics_id CHECK (google_analytics_measurement_id = '' OR google_analytics_measurement_id ~ '^G-[A-Z0-9]{10}$')");
        $this->addSql("UPDATE site_settings SET cookie_consent_message = '".self::NEW_DEFAULT_CONSENT_MESSAGE."' WHERE cookie_consent_message IN ('".self::OLD_DEFAULT_CONSENT_MESSAGE."', '".self::LEGACY_ENTITY_DEFAULT_CONSENT_MESSAGE."')");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $configuredSites = $this->connection->fetchOne("SELECT COUNT(*) FROM site_settings WHERE google_analytics_measurement_id <> ''");
        if (!in_array($configuredSites, [0, '0'], true)) {
            throw new \RuntimeException('Die konfigurierte Analytics-Mess-ID muss vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_google_analytics_id');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN google_analytics_measurement_id');
    }
}
