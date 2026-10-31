<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds review, rejection, and censorship actions to comment word filters.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_comment_filter_terms_normalized');
        $this->addSql("ALTER TABLE comment_filter_terms ADD action VARCHAR(16) DEFAULT 'review' NOT NULL");
        $this->addSql("ALTER TABLE comment_filter_terms ADD CONSTRAINT chk_comment_filter_terms_action CHECK (action IN ('review', 'reject', 'censor'))");
        $this->addSql('CREATE UNIQUE INDEX uniq_comment_filter_terms_normalized_action ON comment_filter_terms (normalized_term, action)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('comment_filter_terms');
        $nonReviewCount = $this->connection->fetchOne("SELECT COUNT(*) FROM comment_filter_terms WHERE action <> 'review'");
        $duplicateTermCount = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT normalized_term FROM comment_filter_terms GROUP BY normalized_term HAVING COUNT(*) > 1) AS duplicate_terms',
        );
        if (!in_array($nonReviewCount, [0, '0'], true) || !in_array($duplicateTermCount, [0, '0'], true)) {
            throw new \RuntimeException('Kommentarfilter-Aktionen dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('DROP INDEX uniq_comment_filter_terms_normalized_action');
        $this->addSql('ALTER TABLE comment_filter_terms DROP CONSTRAINT chk_comment_filter_terms_action');
        $this->addSql('ALTER TABLE comment_filter_terms DROP action');
        $this->addSql('CREATE UNIQUE INDEX uniq_comment_filter_terms_normalized ON comment_filter_terms (normalized_term)');
    }
}
