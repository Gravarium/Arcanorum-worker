<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speichert private Speicherlöschungen für sichere Wiederholungsvorgänge.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE private_object_deletion_jobs (id VARCHAR(32) NOT NULL, object_id VARCHAR(64) NOT NULL, attempt_count INTEGER DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT uniq_private_object_deletion_object UNIQUE (object_id), CONSTRAINT chk_private_object_deletion_attempts CHECK (attempt_count >= 0), CONSTRAINT chk_private_object_deletion_object_id CHECK (object_id ~ '^[a-f0-9]{64}$'))");
        $this->addSql('CREATE INDEX idx_private_object_deletion_available ON private_object_deletion_jobs (available_at, created_at)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('private_object_deletion_jobs');
        $deletionJobs = $this->connection->fetchOne('SELECT COUNT(*) FROM private_object_deletion_jobs');
        if (!in_array($deletionJobs, [0, '0'], true)) {
            throw new \RuntimeException('Aufträge zur sicheren Löschung privater Speicherobjekte müssen vor dem Migrationsrückbau erhalten bleiben.');
        }

        $this->addSql('DROP TABLE private_object_deletion_jobs');
    }
}
