<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt Seriengenres um eine begrenzte, sortierbare Startseitenhervorhebung.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE series_genres ADD is_featured BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE series_genres ADD featured_sort_order SMALLINT NOT NULL DEFAULT 0');
        $this->addSql(<<<'SQL'
            ALTER TABLE series_genres
            ADD CONSTRAINT chk_series_genres_featured_sort_order
            CHECK (featured_sort_order BETWEEN 0 AND 10000)
            SQL);
        $this->addSql('CREATE INDEX idx_series_genres_featured_order ON series_genres (featured_sort_order, name, id) WHERE is_featured IS TRUE');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('series_genres');
        $customized = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM series_genres
            WHERE is_featured IS TRUE OR featured_sort_order <> 0
            SQL);
        if (!in_array($customized, [0, '0'], true)) {
            $this->abortIf(true, 'Cannot discard featured series genre choices during migration rollback.');
        }

        $this->addSql('DROP INDEX idx_series_genres_featured_order');
        $this->addSql('ALTER TABLE series_genres DROP CONSTRAINT chk_series_genres_featured_sort_order');
        $this->addSql('ALTER TABLE series_genres DROP COLUMN featured_sort_order');
        $this->addSql('ALTER TABLE series_genres DROP COLUMN is_featured');
    }
}
