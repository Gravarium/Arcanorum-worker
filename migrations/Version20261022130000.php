<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt eine begrenzte, persistente Grundkonfiguration der Website.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE site_settings (id SMALLINT NOT NULL, site_name VARCHAR(80) NOT NULL, tagline VARCHAR(160) NOT NULL, default_locale VARCHAR(8) NOT NULL, timezone VARCHAR(64) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_site_settings_singleton CHECK (id = 1), CONSTRAINT chk_site_settings_name CHECK (LENGTH(BTRIM(site_name)) BETWEEN 1 AND 80), CONSTRAINT chk_site_settings_locale CHECK (default_locale IN (\'de\')))');
        $this->addSql("INSERT INTO site_settings (id, site_name, tagline, default_locale, timezone, updated_at) VALUES (1, 'Arcanorum', '', 'de', 'Europe/Berlin', CURRENT_TIMESTAMP AT TIME ZONE 'UTC')");
    }

    public function down(Schema $schema): void
    {
        $customSettings = $this->connection->fetchOne("SELECT COUNT(*) FROM site_settings WHERE site_name <> 'Arcanorum' OR tagline <> '' OR default_locale <> 'de' OR timezone <> 'Europe/Berlin'");
        if (!in_array($customSettings, [0, '0'], true)) {
            throw new \RuntimeException('Geänderte Website-Einstellungen dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('DROP TABLE site_settings');
    }
}
