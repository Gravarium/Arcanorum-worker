<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028223107 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fügt sichere, idempotente Automatisierungsaufträge und externe Videoquellenprüfungen hinzu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sitemap_content_revision (id SMALLINT NOT NULL, generation VARCHAR(32) NOT NULL, revision BIGINT NOT NULL DEFAULT 0, CONSTRAINT pk_sitemap_content_revision PRIMARY KEY (id), CONSTRAINT chk_sitemap_content_revision_singleton CHECK (id = 1), CONSTRAINT chk_sitemap_content_revision_nonnegative CHECK (revision >= 0))');
        $this->addSql('INSERT INTO sitemap_content_revision (id, generation, revision) VALUES (1, :generation, 0)', ['generation' => bin2hex(random_bytes(16))]);
        $this->addSql('GRANT SELECT ON sitemap_content_revision TO PUBLIC');
        $this->addSql('CREATE FUNCTION increment_sitemap_content_revision() RETURNS TRIGGER LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public AS $$ BEGIN UPDATE sitemap_content_revision SET revision = revision + 1 WHERE id = 1; RETURN NULL; END $$');
        $sitemapRelevantColumns = [
            'news_articles' => 'title, slug, summary, body, status, members_only, published_at, thumbnail_object_id, updated_at',
            'videos' => 'title, description, source_type, source_url, uploaded_object_id, uploaded_mime_type, uploaded_size, thumbnail_object_id, thumbnail_mime_type, thumbnail_size, duration_seconds, status, published_at, updated_at',
            'static_pages' => 'title, slug, status, published_at, updated_at',
            'content_series' => 'title, slug, description, status, published_at, updated_at',
            'series_episodes' => 'series_id, video_id, season_number, episode_number, status, updated_at',
            'site_settings' => 'module_news_enabled, module_series_enabled, module_pages_enabled',
        ];
        foreach ($sitemapRelevantColumns as $table => $columns) {
            $this->addSql(sprintf(
                'CREATE TRIGGER trg_sitemap_revision_%s AFTER INSERT OR DELETE OR UPDATE OF %s ON %s FOR EACH STATEMENT EXECUTE FUNCTION increment_sitemap_content_revision()',
                $table,
                $columns,
                $table,
            ));
        }

        $this->addSql('ALTER TABLE videos ADD external_source_status VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE videos ADD external_source_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("ALTER TABLE videos ADD CONSTRAINT chk_video_external_source_status CHECK (external_source_status IS NULL OR external_source_status IN ('available', 'unavailable', 'unsupported'))");
        $this->addSql('CREATE INDEX idx_videos_external_source_check ON videos (source_type, external_source_checked_at)');

        $this->addSql("CREATE TABLE scheduled_tasks (
            id VARCHAR(32) NOT NULL,
            task_key VARCHAR(80) NOT NULL,
            type VARCHAR(32) NOT NULL,
            name VARCHAR(120) NOT NULL,
            enabled BOOLEAN DEFAULT TRUE NOT NULL,
            is_system BOOLEAN DEFAULT FALSE NOT NULL,
            interval_minutes INT NOT NULL,
            configuration JSON NOT NULL,
            next_run_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            last_run_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT pk_scheduled_tasks PRIMARY KEY (id),
            CONSTRAINT uniq_scheduled_task_key UNIQUE (task_key),
            CONSTRAINT chk_scheduled_task_type CHECK (type IN ('video_source_check', 'provider_import', 'sitemap_refresh')),
            CONSTRAINT chk_scheduled_task_interval CHECK (interval_minutes BETWEEN 15 AND 10080),
            CONSTRAINT chk_scheduled_task_system_type CHECK ((type = 'video_source_check') = is_system),
            CONSTRAINT chk_scheduled_task_fixed_key CHECK ((type = 'video_source_check' AND task_key = 'video-source-health-check' AND configuration::jsonb = '{}'::jsonb) OR (type <> 'video_source_check' AND task_key <> 'video-source-health-check'))
        )");
        $this->addSql('CREATE INDEX idx_scheduled_task_due ON scheduled_tasks (enabled, next_run_at)');

        $this->addSql("CREATE TABLE scheduled_task_runs (
            id VARCHAR(32) NOT NULL,
            task_id VARCHAR(32) DEFAULT NULL,
            task_key VARCHAR(80) NOT NULL,
            task_name VARCHAR(120) NOT NULL,
            scheduled_for TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            status VARCHAR(16) NOT NULL,
            scheduler_identity VARCHAR(64) NOT NULL,
            result_summary VARCHAR(500) DEFAULT NULL,
            CONSTRAINT pk_scheduled_task_runs PRIMARY KEY (id),
            CONSTRAINT uniq_scheduled_task_run_due UNIQUE (task_id, scheduled_for),
            CONSTRAINT fk_scheduled_task_runs_task FOREIGN KEY (task_id) REFERENCES scheduled_tasks (id) ON DELETE SET NULL,
            CONSTRAINT chk_scheduled_task_run_status CHECK (status IN ('running', 'succeeded', 'failed'))
        )");
        $this->addSql('CREATE INDEX idx_scheduled_task_runs_recent ON scheduled_task_runs (started_at)');
        $this->addSql('CREATE INDEX idx_scheduled_task_runs_task_recent ON scheduled_task_runs (task_key, started_at)');

        $this->addSql('CREATE TABLE scheduler_invocations (
            nonce_digest CHAR(64) NOT NULL,
            scheduler_identity VARCHAR(64) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            CONSTRAINT pk_scheduler_invocations PRIMARY KEY (nonce_digest)
        )');
        $this->addSql('CREATE INDEX idx_scheduler_invocations_expiry ON scheduler_invocations (expires_at)');

        $this->addSql("INSERT INTO scheduled_tasks (
                id, task_key, type, name, enabled, is_system, interval_minutes,
                configuration, next_run_at, created_at, updated_at
            ) VALUES (
                '00000000000000000000000000000031',
                'video-source-health-check',
                'video_source_check',
                'Entfernte Videoquellen zur Moderation melden',
                TRUE,
                TRUE,
                60,
                '{}'::json,
                CURRENT_TIMESTAMP + INTERVAL '60 minutes',
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('scheduled_task_runs', 'scheduled_tasks', 'scheduler_invocations', 'sitemap_content_revision', 'videos');
        $configuredTasks = $this->connection->fetchOne("SELECT COUNT(*) FROM scheduled_tasks WHERE task_key <> 'video-source-health-check'");
        $recordedRuns = $this->connection->fetchOne('SELECT COUNT(*) FROM scheduled_task_runs');
        $unexpiredInvocations = $this->connection->fetchOne('SELECT COUNT(*) FROM scheduler_invocations WHERE expires_at > CURRENT_TIMESTAMP');
        if (!in_array($configuredTasks, [0, '0'], true)
            || !in_array($recordedRuns, [0, '0'], true)
            || !in_array($unexpiredInvocations, [0, '0'], true)
        ) {
            throw new \RuntimeException('Automatisierungsaufträge, Laufprotokolle und noch gültige Scheduler-Nonces dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        foreach (['news_articles', 'videos', 'static_pages', 'content_series', 'series_episodes', 'site_settings'] as $table) {
            $this->addSql(sprintf('DROP TRIGGER trg_sitemap_revision_%s ON %s', $table, $table));
        }
        $this->addSql('DROP FUNCTION increment_sitemap_content_revision()');
        $this->addSql('DROP TABLE sitemap_content_revision');
        $this->addSql('DROP TABLE scheduler_invocations');
        $this->addSql('DROP TABLE scheduled_task_runs');
        $this->addSql('DROP TABLE scheduled_tasks');
        $this->addSql('DROP INDEX idx_videos_external_source_check');
        $this->addSql('ALTER TABLE videos DROP CONSTRAINT chk_video_external_source_status');
        $this->addSql('ALTER TABLE videos DROP external_source_checked_at');
        $this->addSql('ALTER TABLE videos DROP external_source_status');
    }
}
