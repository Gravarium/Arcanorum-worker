<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a safe video-player ratio and a site-wide public embedding switch.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD video_player_aspect_ratio VARCHAR(4) NOT NULL DEFAULT '16:9'");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_video_player_aspect_ratio CHECK (video_player_aspect_ratio IN ('16:9', '4:3', '1:1', '9:16'))");
        $this->addSql('ALTER TABLE site_settings ADD public_video_embedding_enabled BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $customizedPlayerSettings = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM site_settings WHERE video_player_aspect_ratio <> '16:9' OR public_video_embedding_enabled IS NOT TRUE",
        );
        if (!in_array($customizedPlayerSettings, [0, '0'], true)) {
            throw new \RuntimeException('Geänderte Player- und Einbettungseinstellungen dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_video_player_aspect_ratio');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN video_player_aspect_ratio');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN public_video_embedding_enabled');
    }
}
