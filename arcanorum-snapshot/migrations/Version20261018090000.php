<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt eine verwaltbare Wortfilterliste für Kommentare.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE comment_filter_terms (id VARCHAR(32) NOT NULL, term VARCHAR(100) NOT NULL, normalized_term VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_comment_filter_terms_term CHECK (char_length(BTRIM(term)) BETWEEN 1 AND 100), CONSTRAINT chk_comment_filter_terms_normalized CHECK (char_length(normalized_term) BETWEEN 1 AND 100))');
        $this->addSql('CREATE UNIQUE INDEX uniq_comment_filter_terms_normalized ON comment_filter_terms (normalized_term)');
        $this->addSql('CREATE INDEX idx_comment_filter_terms_created ON comment_filter_terms (created_at)');
        $this->addSql('ALTER TABLE content_comments ADD filtered_by_term VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_comments ADD CONSTRAINT chk_content_comments_filtered_by_term CHECK (filtered_by_term IS NULL OR char_length(BTRIM(filtered_by_term)) BETWEEN 1 AND 100)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('comment_filter_terms', 'content_comments');
        $filterData = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM (
                SELECT 1 FROM comment_filter_terms
                UNION ALL
                SELECT 1 FROM content_comments WHERE filtered_by_term IS NOT NULL
            ) AS comment_filter_data
            SQL);
        if (!in_array($filterData, [0, '0'], true)) {
            throw new \RuntimeException('Kommentar-Filterregeln und dokumentierte Treffer müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('ALTER TABLE content_comments DROP CONSTRAINT chk_content_comments_filtered_by_term');
        $this->addSql('ALTER TABLE content_comments DROP filtered_by_term');
        $this->addSql('DROP INDEX idx_comment_filter_terms_created');
        $this->addSql('DROP INDEX uniq_comment_filter_terms_normalized');
        $this->addSql('DROP TABLE comment_filter_terms');
    }
}
