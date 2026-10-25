<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt sichere öffentliche Theme-, Layout- und Farboptionen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD public_theme VARCHAR(32) NOT NULL DEFAULT 'arcanorum'");
        $this->addSql("ALTER TABLE site_settings ADD public_layout VARCHAR(24) NOT NULL DEFAULT 'grid'");
        $this->addSql("ALTER TABLE site_settings ADD theme_primary_color VARCHAR(7) NOT NULL DEFAULT '#24365E'");
        $this->addSql("ALTER TABLE site_settings ADD theme_accent_color VARCHAR(7) NOT NULL DEFAULT '#2F6E68'");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_public_theme CHECK (public_theme IN ('arcanorum', 'editorial', 'arcade'))");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_public_layout CHECK (public_layout IN ('grid', 'magazine', 'compact'))");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_theme_primary_color CHECK (theme_primary_color ~ '^#[0-9A-Fa-f]{6}$')");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_theme_accent_color CHECK (theme_accent_color ~ '^#[0-9A-Fa-f]{6}$')");
    }

    public function down(Schema $schema): void
    {
        $configured = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM site_settings WHERE public_theme <> 'arcanorum' OR public_layout <> 'grid' OR theme_primary_color <> '#24365E' OR theme_accent_color <> '#2F6E68'",
        );
        if (!in_array($configured, [0, '0'], true)) {
            throw new \RuntimeException('Abweichende Designoptionen dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_public_theme');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_public_layout');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_theme_primary_color');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_theme_accent_color');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN public_theme');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN public_layout');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN theme_primary_color');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN theme_accent_color');
    }
}
