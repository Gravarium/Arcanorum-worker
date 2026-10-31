<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt datensparsame Aufrufzahlen für beliebte Videos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE videos ADD view_count INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_videos_status_views ON videos (status, view_count, published_at)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('videos');
        $views = $this->connection->fetchOne('SELECT COUNT(*) FROM videos WHERE view_count <> 0');
        if (!in_array($views, [0, '0'], true)) {
            throw new \RuntimeException('Gespeicherte Videoaufrufzahlen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP INDEX idx_videos_status_views');
        $this->addSql('ALTER TABLE videos DROP view_count');
    }
}
