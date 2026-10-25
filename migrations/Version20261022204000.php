<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022204000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt sichere Player-, Kommentar- und Einreichungsoptionen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD player_autoplay_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE site_settings ADD player_autoplay_muted BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE site_settings ADD default_comments_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE site_settings ADD public_video_submissions_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_player_autoplay_muted CHECK (NOT player_autoplay_enabled OR player_autoplay_muted)');
    }

    public function down(Schema $schema): void
    {
        $customizedOptions = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM site_settings WHERE player_autoplay_enabled IS NOT FALSE OR player_autoplay_muted IS NOT TRUE OR default_comments_enabled IS NOT TRUE OR public_video_submissions_enabled IS NOT TRUE',
        );
        if (!in_array($customizedOptions, [0, '0'], true)) {
            throw new \RuntimeException('Konfigurierte Inhaltsoptionen dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_player_autoplay_muted');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN player_autoplay_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN player_autoplay_muted');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN default_comments_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN public_video_submissions_enabled');
    }
}
