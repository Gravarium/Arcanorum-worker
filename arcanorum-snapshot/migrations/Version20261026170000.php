<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add allowlisted video player color presets and an optional same-site watermark.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD video_player_color_scheme VARCHAR(16) NOT NULL DEFAULT 'midnight'");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_video_player_color_scheme CHECK (video_player_color_scheme IN ('midnight', 'ocean', 'forest', 'ember'))");
        $this->addSql('ALTER TABLE site_settings ADD video_player_watermark_enabled BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $customizedSettings = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM site_settings WHERE video_player_color_scheme <> 'midnight' OR video_player_watermark_enabled = TRUE",
        );
        if (!in_array($customizedSettings, [0, '0'], true)) {
            throw new \RuntimeException('Player-Farbschema und Wasserzeichen müssen vor dem Migrationsrückbau zurückgesetzt werden.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_video_player_color_scheme');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN video_player_watermark_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN video_player_color_scheme');
    }
}
