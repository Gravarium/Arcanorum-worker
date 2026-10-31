<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022140000 extends AbstractMigration
{
    private const string DEFAULT_MAINTENANCE_MESSAGE = 'Die Website wird gewartet. Bitte versuchen Sie es später erneut.';
    private const string DEFAULT_COOKIE_CONSENT_MESSAGE = 'Wir verwenden notwendige Cookies für Anmeldung und Sicherheit. Derzeit setzen wir keine Analyse- oder Werbecookies.';

    public function getDescription(): string
    {
        return 'Ergänzt Indexierungs-, Wartungs- und Cookie-Einwilligungseinstellungen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD seo_description VARCHAR(320) NOT NULL DEFAULT ''");
        $this->addSql('ALTER TABLE site_settings ADD search_indexing_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE site_settings ADD maintenance_mode_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql("ALTER TABLE site_settings ADD maintenance_message VARCHAR(320) NOT NULL DEFAULT '".self::DEFAULT_MAINTENANCE_MESSAGE."'");
        $this->addSql('ALTER TABLE site_settings ADD cookie_consent_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql("ALTER TABLE site_settings ADD cookie_consent_message VARCHAR(320) NOT NULL DEFAULT '".self::DEFAULT_COOKIE_CONSENT_MESSAGE."'");
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_maintenance_message CHECK (LENGTH(BTRIM(maintenance_message)) BETWEEN 1 AND 320)');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_cookie_message CHECK (LENGTH(BTRIM(cookie_consent_message)) BETWEEN 1 AND 320)');
    }

    public function down(Schema $schema): void
    {
        $customSettings = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM site_settings WHERE seo_description <> '' OR search_indexing_enabled IS NOT TRUE OR maintenance_mode_enabled IS NOT FALSE OR maintenance_message <> '".self::DEFAULT_MAINTENANCE_MESSAGE."' OR cookie_consent_enabled IS NOT TRUE OR cookie_consent_message <> '".self::DEFAULT_COOKIE_CONSENT_MESSAGE."'",
        );
        if (!in_array($customSettings, [0, '0'], true)) {
            throw new \RuntimeException('Geänderte SEO-, Wartungs- oder Cookie-Einstellungen dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_maintenance_message');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_cookie_message');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN seo_description');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN search_indexing_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN maintenance_mode_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN maintenance_message');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN cookie_consent_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN cookie_consent_message');
    }
}
