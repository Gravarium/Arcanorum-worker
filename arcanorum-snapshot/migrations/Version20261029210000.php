<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speichert die bevorzugte Auflösung für private Provider-Vorschaubilder.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD provider_thumbnail_resolution VARCHAR(16) DEFAULT 'medium' NOT NULL");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_provider_thumbnail_resolution CHECK (provider_thumbnail_resolution IN ('small', 'medium', 'large', 'extra-large', 'original'))");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $configuredResolutions = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM site_settings WHERE provider_thumbnail_resolution <> 'medium'",
        );
        if (!in_array($configuredResolutions, [0, '0'], true)) {
            throw new \RuntimeException('Die konfigurierte Auflösung für Provider-Vorschaubilder muss vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_provider_thumbnail_resolution');
        $this->addSql('ALTER TABLE site_settings DROP provider_thumbnail_resolution');
    }
}
