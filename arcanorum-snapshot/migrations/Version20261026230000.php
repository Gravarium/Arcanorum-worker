<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speichert die Dauer von Videos in Sekunden.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE videos ADD duration_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE videos ADD CONSTRAINT chk_videos_duration_seconds CHECK (duration_seconds IS NULL OR duration_seconds BETWEEN 0 AND 2147483647)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('videos');
        $storedDurations = $this->connection->fetchOne('SELECT COUNT(*) FROM videos WHERE duration_seconds IS NOT NULL');
        if (!in_array($storedDurations, [0, '0'], true)) {
            throw new \RuntimeException('Gespeicherte Videolaufzeiten dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE videos DROP CONSTRAINT chk_videos_duration_seconds');
        $this->addSql('ALTER TABLE videos DROP COLUMN duration_seconds');
    }
}
