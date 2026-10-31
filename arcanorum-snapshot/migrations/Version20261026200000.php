<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add separate responsive width and muted featured autoplay settings for the home player.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD video_player_home_max_width VARCHAR(8) NOT NULL DEFAULT 'fluid'");
        $this->addSql(
            'ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_video_player_home_max_width '
            ."CHECK (video_player_home_max_width IN ('fluid', '640', '800', '1024'))",
        );
        $this->addSql('ALTER TABLE site_settings ADD player_featured_autoplay_enabled BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $customizedSettings = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM site_settings '
            ."WHERE video_player_home_max_width <> 'fluid' OR player_featured_autoplay_enabled IS TRUE",
        );
        if (!in_array($customizedSettings, [0, '0'], true)) {
            throw new \RuntimeException('Die Einstellungen für den Startseitenplayer dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_video_player_home_max_width');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN video_player_home_max_width');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN player_featured_autoplay_enabled');
    }
}
