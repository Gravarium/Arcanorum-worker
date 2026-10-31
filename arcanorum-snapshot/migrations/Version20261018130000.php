<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fügt eine wartende Videoeinreichungs- und Freigabewarteschlange hinzu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE video_submissions (id VARCHAR(32) NOT NULL, video_id VARCHAR(32) NOT NULL, status VARCHAR(16) DEFAULT 'pending' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, reviewed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT uniq_video_submissions_video UNIQUE (video_id), CONSTRAINT fk_video_submissions_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE, CONSTRAINT chk_video_submissions_status CHECK (status IN ('pending', 'approved', 'rejected')), CONSTRAINT chk_video_submissions_reviewed_at CHECK ((status = 'pending' AND reviewed_at IS NULL) OR (status <> 'pending' AND reviewed_at IS NOT NULL)))");
        $this->addSql('CREATE INDEX idx_video_submissions_status_created ON video_submissions (status, created_at)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('video_submissions');
        $submissions = $this->connection->fetchOne('SELECT COUNT(*) FROM video_submissions');
        if (!in_array($submissions, [0, '0'], true)) {
            throw new \RuntimeException('Gespeicherte Videoeinreichungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP INDEX idx_video_submissions_status_created');
        $this->addSql('DROP TABLE video_submissions');
    }
}
