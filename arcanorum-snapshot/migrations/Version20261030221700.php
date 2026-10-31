<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030221700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt eine begrenzte Schwelle zur automatischen Hervorhebung gezählter Videos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD auto_feature_view_threshold INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_auto_feature_view_threshold CHECK (auto_feature_view_threshold >= 0)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $configuredThresholds = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM site_settings WHERE auto_feature_view_threshold <> 0',
        );
        if (!in_array($configuredThresholds, [0, '0'], true)) {
            throw new \RuntimeException('Die konfigurierte Schwelle für automatische Video-Hervorhebung muss vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_auto_feature_view_threshold');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN auto_feature_view_threshold');
    }
}
