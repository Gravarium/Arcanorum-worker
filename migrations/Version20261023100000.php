<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erlaubt die Auswahl der vollständig übersetzten englischen Sprache.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_locale');
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_locale CHECK (default_locale IN ('de', 'en'))");
    }

    public function down(Schema $schema): void
    {
        $englishSettings = $this->connection->fetchOne("SELECT COUNT(*) FROM site_settings WHERE default_locale <> 'de'");
        if (!in_array($englishSettings, [0, '0'], true)) {
            throw new \RuntimeException('Die englische Website-Sprache muss vor dem Migrationsrückbau geändert werden.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_locale');
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_locale CHECK (default_locale IN ('de'))");
    }
}
