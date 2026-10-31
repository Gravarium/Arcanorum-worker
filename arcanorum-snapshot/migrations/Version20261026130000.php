<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow the partially translated Arabic right-to-left interface locale.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_locale');
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_locale CHECK (default_locale IN ('de', 'en', 'ar'))");
    }

    public function down(Schema $schema): void
    {
        $arabicSettings = $this->connection->fetchOne("SELECT COUNT(*) FROM site_settings WHERE default_locale = 'ar'");
        if (!in_array($arabicSettings, [0, '0'], true)) {
            throw new \RuntimeException('Die arabische Website-Sprache muss vor dem Migrationsrückbau geändert werden.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_locale');
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_locale CHECK (default_locale IN ('de', 'en'))");
    }
}
