<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029170500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Führt Ablauf-Leases, begrenzte Wiederholungen und Versionsschutz für geplante Aufgaben ein.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scheduled_task_runs ADD attempt_count INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE scheduled_task_runs ADD last_attempt_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE scheduled_task_runs ADD lease_token_digest VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE scheduled_task_runs ADD lease_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE scheduled_task_runs ADD retry_after TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE scheduled_task_runs ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('UPDATE scheduled_task_runs SET last_attempt_at = started_at');
        $this->addSql("UPDATE scheduled_task_runs SET status = 'failed', result_summary = 'Der Prozess wurde bei der Scheduler-Aktualisierung unterbrochen; der Auftrag wird erneut geprüft.', finished_at = COALESCE(finished_at, started_at) WHERE status = 'running'");
        $this->addSql('ALTER TABLE scheduled_task_runs ALTER COLUMN last_attempt_at SET NOT NULL');
        $this->addSql('ALTER TABLE scheduled_task_runs ADD CONSTRAINT chk_scheduled_task_run_attempt_count CHECK (attempt_count BETWEEN 1 AND 10)');
        $this->addSql("ALTER TABLE scheduled_task_runs ADD CONSTRAINT chk_scheduled_task_run_lease CHECK ((status = 'running' AND lease_token_digest IS NOT NULL AND lease_expires_at IS NOT NULL AND finished_at IS NULL) OR (status <> 'running' AND lease_token_digest IS NULL AND lease_expires_at IS NULL))");
        $this->addSql("ALTER TABLE scheduled_task_runs ADD CONSTRAINT chk_scheduled_task_run_lease_digest CHECK (lease_token_digest IS NULL OR lease_token_digest ~ '^[a-f0-9]{64}$')");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('scheduled_task_runs');
        $runCount = $this->connection->fetchOne('SELECT COUNT(*) FROM scheduled_task_runs');
        if (!in_array($runCount, [0, '0'], true)) {
            throw new \RuntimeException('Aufgabenlauf- und Wiederholungsnachweise dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE scheduled_task_runs DROP CONSTRAINT chk_scheduled_task_run_lease_digest');
        $this->addSql('ALTER TABLE scheduled_task_runs DROP CONSTRAINT chk_scheduled_task_run_lease');
        $this->addSql('ALTER TABLE scheduled_task_runs DROP CONSTRAINT chk_scheduled_task_run_attempt_count');
        $this->addSql('ALTER TABLE scheduled_task_runs DROP version');
        $this->addSql('ALTER TABLE scheduled_task_runs DROP retry_after');
        $this->addSql('ALTER TABLE scheduled_task_runs DROP lease_expires_at');
        $this->addSql('ALTER TABLE scheduled_task_runs DROP lease_token_digest');
        $this->addSql('ALTER TABLE scheduled_task_runs DROP last_attempt_at');
        $this->addSql('ALTER TABLE scheduled_task_runs DROP attempt_count');
    }
}
