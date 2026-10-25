<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt eine allowlist-gesicherte Auswahl zwischen nativer HTML5- und Video.js-Wiedergabe.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD video_player_engine VARCHAR(16) NOT NULL DEFAULT 'native'");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_video_player_engine CHECK (video_player_engine IN ('native', 'videojs'))");
    }

    public function down(Schema $schema): void
    {
        $customizedEngines = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM site_settings WHERE video_player_engine <> 'native'",
        );
        if (!in_array($customizedEngines, [0, '0'], true)) {
            throw new \RuntimeException('Die ausgewählte Video-Player-Einstellung darf durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_video_player_engine');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN video_player_engine');
    }
}
