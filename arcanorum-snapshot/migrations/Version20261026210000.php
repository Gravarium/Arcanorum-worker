<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Allow administrators to assign safe alternate stream qualities to published videos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE video_quality_sources ('
            .'id VARCHAR(32) NOT NULL, '
            .'video_id VARCHAR(32) NOT NULL, '
            .'resolution VARCHAR(8) NOT NULL, '
            .'source_url VARCHAR(2048) NOT NULL, '
            .'created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            .'updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            .'PRIMARY KEY (id), '
            .'CONSTRAINT chk_video_quality_resolution CHECK (resolution IN (\'240p\', \'360p\', \'480p\', \'720p\', \'1080p\', \'1440p\', \'2160p\')), '
            .'CONSTRAINT chk_video_quality_source_https CHECK (source_url LIKE \'https://%\' AND source_url !~ \'[[:space:][:cntrl:]]\'), '
            .'CONSTRAINT fk_video_quality_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE'
            .')',
        );
        $this->addSql('CREATE UNIQUE INDEX uniq_video_quality_resolution ON video_quality_sources (video_id, resolution)');
        $this->addSql('CREATE UNIQUE INDEX uniq_video_quality_source_url ON video_quality_sources (video_id, source_url)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('video_quality_sources');
        $sourceCount = $this->connection->fetchOne('SELECT COUNT(*) FROM video_quality_sources');
        if (!in_array($sourceCount, [0, '0'], true)) {
            throw new \RuntimeException('Videoqualitätsquellen dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('DROP TABLE video_quality_sources');
    }
}
