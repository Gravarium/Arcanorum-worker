<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261107130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt konfigurierbare Social- und Community-Aktivitäten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings
            ADD module_social_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            ADD social_following_limit INTEGER NOT NULL DEFAULT 500,
            ADD social_activity_video_submission_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            ADD social_activity_playlist_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            ADD social_activity_video_comment_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            ADD social_activity_news_comment_enabled BOOLEAN NOT NULL DEFAULT TRUE,
            ADD CONSTRAINT chk_site_settings_social_following_limit CHECK (social_following_limit BETWEEN 0 AND 5000)");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');

        $customizedSettings = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM site_settings WHERE module_social_enabled IS NOT TRUE '
            .'OR social_following_limit <> 500 '
            .'OR social_activity_video_submission_enabled IS NOT TRUE '
            .'OR social_activity_playlist_enabled IS NOT TRUE '
            .'OR social_activity_video_comment_enabled IS NOT TRUE '
            .'OR social_activity_news_comment_enabled IS NOT TRUE',
        );
        if (!in_array($customizedSettings, [0, '0'], true)) {
            throw new \RuntimeException('Konfigurierte Social- und Community-Einstellungen dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_social_following_limit');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN module_social_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN social_following_limit');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN social_activity_video_submission_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN social_activity_playlist_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN social_activity_video_comment_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN social_activity_news_comment_enabled');
    }
}
