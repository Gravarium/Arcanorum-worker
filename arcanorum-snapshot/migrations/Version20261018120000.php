<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fügt begründete Videomeldungen mit Adminmoderation hinzu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE video_reports (id VARCHAR(32) NOT NULL, video_id VARCHAR(32) NOT NULL, reason VARCHAR(24) NOT NULL, details TEXT NOT NULL, status VARCHAR(16) DEFAULT 'pending' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT fk_video_reports_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE, CONSTRAINT chk_video_reports_reason CHECK (reason IN ('broken_media', 'inappropriate', 'copyright', 'misleading', 'other')), CONSTRAINT chk_video_reports_status CHECK (status IN ('pending', 'dismissed', 'video_hidden')), CONSTRAINT chk_video_reports_details CHECK (char_length(details) <= 1000), CONSTRAINT chk_video_reports_reviewed_at CHECK ((status = 'pending' AND reviewed_at IS NULL) OR (status <> 'pending' AND reviewed_at IS NOT NULL)))");
        $this->addSql('CREATE INDEX idx_video_reports_status_created ON video_reports (status, created_at)');
        $this->addSql('CREATE INDEX idx_video_reports_video_created ON video_reports (video_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('video_reports');
        $reports = $this->connection->fetchOne('SELECT COUNT(*) FROM video_reports');
        if (!in_array($reports, [0, '0'], true)) {
            throw new \RuntimeException('Gespeicherte Videomeldungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP INDEX idx_video_reports_video_created');
        $this->addSql('DROP INDEX idx_video_reports_status_created');
        $this->addSql('DROP TABLE video_reports');
    }
}
