<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verwaltet redaktionelle Serien und ordnet Videos Staffeln und Folgen zu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE content_series (id VARCHAR(32) NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(200) NOT NULL, description TEXT NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT chk_content_series_status CHECK (status IN (\'draft\', \'published\')))');
        $this->addSql('CREATE UNIQUE INDEX uniq_content_series_slug ON content_series (slug)');
        $this->addSql('CREATE INDEX idx_content_series_status_created ON content_series (status, created_at)');

        $this->addSql('CREATE TABLE series_episodes (id VARCHAR(32) NOT NULL, series_id VARCHAR(32) NOT NULL, video_id VARCHAR(32) NOT NULL, season_number SMALLINT NOT NULL, episode_number SMALLINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_series_episodes_season_positive CHECK (season_number > 0), CONSTRAINT chk_series_episodes_episode_positive CHECK (episode_number > 0))');
        $this->addSql('CREATE UNIQUE INDEX uniq_series_episodes_position ON series_episodes (series_id, season_number, episode_number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_series_episodes_video ON series_episodes (video_id)');
        $this->addSql('CREATE INDEX idx_series_episodes_order ON series_episodes (series_id, season_number, episode_number)');
        $this->addSql('ALTER TABLE series_episodes ADD CONSTRAINT fk_series_episodes_series FOREIGN KEY (series_id) REFERENCES content_series (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE series_episodes ADD CONSTRAINT fk_series_episodes_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('content_series', 'series_episodes');
        $episodes = $guard->countRows('SELECT COUNT(*) FROM series_episodes');
        $series = $guard->countRows('SELECT COUNT(*) FROM content_series');
        if ($episodes > 0 || $series > 0) {
            throw new \RuntimeException('Redaktionelle Serien und Episodenzuordnungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE series_episodes');
        $this->addSql('DROP TABLE content_series');
    }
}
