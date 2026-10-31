<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt begrenzte öffentliche Anzeigeoptionen und datensparsame Videoaufruf-Aggregate.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD homepage_featured_limit SMALLINT NOT NULL DEFAULT 1');
        $this->addSql('ALTER TABLE site_settings ADD homepage_video_limit SMALLINT NOT NULL DEFAULT 6');
        $this->addSql('ALTER TABLE site_settings ADD homepage_news_limit SMALLINT NOT NULL DEFAULT 6');
        $this->addSql('ALTER TABLE site_settings ADD public_video_page_limit SMALLINT NOT NULL DEFAULT 24');
        $this->addSql("ALTER TABLE site_settings ADD popular_video_sort VARCHAR(16) NOT NULL DEFAULT 'views'");
        $this->addSql('ALTER TABLE site_settings ADD popular_video_period_days SMALLINT NOT NULL DEFAULT 30');
        $this->addSql('ALTER TABLE site_settings ADD currently_watching_limit SMALLINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE site_settings ADD public_statistics_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE site_settings ADD tag_cloud_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE site_settings ADD tag_cloud_limit SMALLINT NOT NULL DEFAULT 20');
        $this->addSql('ALTER TABLE site_settings ADD tag_cloud_shuffle BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE site_settings ADD related_video_limit SMALLINT NOT NULL DEFAULT 6');
        $this->addSql('ALTER TABLE site_settings ADD top_rated_video_limit SMALLINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE site_settings ADD video_share_widget_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_homepage_featured_limit CHECK (homepage_featured_limit BETWEEN 1 AND 8)');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_homepage_video_limit CHECK (homepage_video_limit BETWEEN 0 AND 24)');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_homepage_news_limit CHECK (homepage_news_limit BETWEEN 0 AND 12)');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_public_video_page_limit CHECK (public_video_page_limit IN (12, 24, 36, 48))');
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_popular_video_sort CHECK (popular_video_sort IN ('views', 'recent_views', 'ratings'))");
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_popular_video_period_days CHECK (popular_video_period_days IN (7, 30, 90))');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_currently_watching_limit CHECK (currently_watching_limit BETWEEN 0 AND 12)');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_tag_cloud_limit CHECK (tag_cloud_limit BETWEEN 0 AND 50)');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_related_video_limit CHECK (related_video_limit BETWEEN 0 AND 12)');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_top_rated_video_limit CHECK (top_rated_video_limit BETWEEN 0 AND 12)');
        $this->addSql('ALTER TABLE videos ADD last_viewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_videos_status_last_viewed ON videos (status, last_viewed_at)');
        $this->addSql('CREATE TABLE video_daily_views (video_id CHAR(32) NOT NULL, view_date DATE NOT NULL, view_count INT NOT NULL DEFAULT 0, PRIMARY KEY(video_id, view_date), CONSTRAINT fk_video_daily_views_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE, CONSTRAINT chk_video_daily_views_count CHECK (view_count BETWEEN 0 AND 2147483647))');
        $this->addSql('CREATE INDEX idx_video_daily_views_date ON video_daily_views (view_date, video_id)');
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('site_settings', 'video_daily_views', 'videos');
        $this->abortIf(
            $guard->countRows('SELECT COUNT(*) FROM video_daily_views') > 0,
            'The migration cannot remove collected daily view aggregates.',
        );
        $this->abortIf(
            $guard->countRows('SELECT COUNT(*) FROM videos WHERE last_viewed_at IS NOT NULL') > 0,
            'The migration cannot remove last-view timestamps.',
        );
        $this->abortIf(
            $guard->countRows(
                "SELECT COUNT(*) FROM site_settings WHERE homepage_featured_limit <> 1 OR homepage_video_limit <> 6 OR homepage_news_limit <> 6 OR public_video_page_limit <> 24 OR popular_video_sort <> 'views' OR popular_video_period_days <> 30 OR currently_watching_limit <> 0 OR public_statistics_enabled IS TRUE OR tag_cloud_enabled IS TRUE OR tag_cloud_limit <> 20 OR tag_cloud_shuffle IS TRUE OR related_video_limit <> 6 OR top_rated_video_limit <> 0 OR video_share_widget_enabled IS NOT TRUE",
            ) > 0,
            'The migration cannot remove customized public display settings.',
        );

        $this->addSql('DROP INDEX idx_video_daily_views_date');
        $this->addSql('DROP TABLE video_daily_views');
        $this->addSql('DROP INDEX idx_videos_status_last_viewed');
        $this->addSql('ALTER TABLE videos DROP last_viewed_at');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_top_rated_video_limit');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_related_video_limit');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_tag_cloud_limit');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_currently_watching_limit');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_popular_video_period_days');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_popular_video_sort');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_public_video_page_limit');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_homepage_news_limit');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_homepage_video_limit');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_homepage_featured_limit');
        $this->addSql('ALTER TABLE site_settings DROP video_share_widget_enabled');
        $this->addSql('ALTER TABLE site_settings DROP top_rated_video_limit');
        $this->addSql('ALTER TABLE site_settings DROP related_video_limit');
        $this->addSql('ALTER TABLE site_settings DROP tag_cloud_shuffle');
        $this->addSql('ALTER TABLE site_settings DROP tag_cloud_limit');
        $this->addSql('ALTER TABLE site_settings DROP tag_cloud_enabled');
        $this->addSql('ALTER TABLE site_settings DROP public_statistics_enabled');
        $this->addSql('ALTER TABLE site_settings DROP currently_watching_limit');
        $this->addSql('ALTER TABLE site_settings DROP popular_video_period_days');
        $this->addSql('ALTER TABLE site_settings DROP popular_video_sort');
        $this->addSql('ALTER TABLE site_settings DROP public_video_page_limit');
        $this->addSql('ALTER TABLE site_settings DROP homepage_news_limit');
        $this->addSql('ALTER TABLE site_settings DROP homepage_video_limit');
        $this->addSql('ALTER TABLE site_settings DROP homepage_featured_limit');
    }
}
