<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
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
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $arabicSettings = $rollbackGuard->countRows("SELECT COUNT(*) FROM site_settings WHERE default_locale = 'ar'");
        if ($arabicSettings > 0) {
            throw new \RuntimeException('Die arabische Website-Sprache muss vor dem Migrationsrückbau geändert werden.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_locale');
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_locale CHECK (default_locale IN ('de', 'en'))");
    }
}
