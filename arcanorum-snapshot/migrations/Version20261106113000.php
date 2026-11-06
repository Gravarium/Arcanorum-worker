<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt begrenzte, aggregierte Aufrufzahlen für öffentliche News.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_articles ADD view_count INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE news_articles ADD CONSTRAINT chk_news_articles_view_count CHECK (view_count BETWEEN 0 AND 2147483647)');
        $this->addSql('CREATE INDEX idx_news_articles_status_views ON news_articles (status, view_count, published_at)');
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('news_articles');
        $viewCounts = $guard->countRows(
            'SELECT COUNT(*) FROM news_articles WHERE view_count <> 0',
            failureMessage: 'Gespeicherte News-Aufrufzahlen konnten vor dem Migrationsrückbau nicht zuverlässig geprüft werden.',
        );
        if ($viewCounts > 0) {
            throw new \RuntimeException('Gespeicherte News-Aufrufzahlen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP INDEX idx_news_articles_status_views');
        $this->addSql('ALTER TABLE news_articles DROP CONSTRAINT chk_news_articles_view_count');
        $this->addSql('ALTER TABLE news_articles DROP view_count');
    }
}
