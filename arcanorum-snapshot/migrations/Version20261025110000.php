<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt eine allowlist-gesicherte Browser-Vorladeeinstellung für lokale Videowiedergabe.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD video_preload_mode VARCHAR(16) NOT NULL DEFAULT 'metadata'");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_video_preload_mode CHECK (video_preload_mode IN ('none', 'metadata', 'auto'))");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $customizedModes = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM site_settings WHERE video_preload_mode <> 'metadata'",
        );
        if (!in_array($customizedModes, [0, '0'], true)) {
            throw new \RuntimeException('Die ausgewählte Vorladeeinstellung darf durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_video_preload_mode');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN video_preload_mode');
    }
}
