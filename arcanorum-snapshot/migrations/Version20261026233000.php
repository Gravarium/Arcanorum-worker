<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add member-only news access, secure news thumbnails, and complete metadata for static editorial pages.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_articles ADD members_only BOOLEAN DEFAULT FALSE NOT NULL, ADD thumbnail_object_id VARCHAR(64) DEFAULT NULL, ADD thumbnail_mime_type VARCHAR(32) DEFAULT NULL, ADD thumbnail_size INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_news_articles_thumbnail_object_id ON news_articles (thumbnail_object_id)');
        $this->addSql("ALTER TABLE news_articles ADD CONSTRAINT chk_news_articles_thumbnail_metadata CHECK ((thumbnail_object_id IS NULL AND thumbnail_mime_type IS NULL AND thumbnail_size IS NULL) OR (thumbnail_object_id IS NOT NULL AND thumbnail_mime_type IS NOT NULL AND thumbnail_size IS NOT NULL AND thumbnail_object_id ~ '^[a-f0-9]{64}$' AND thumbnail_mime_type IN ('image/jpeg', 'image/png', 'image/webp') AND thumbnail_size BETWEEN 1 AND 4194304))");
        $this->addSql('ALTER TABLE news_article_revisions ADD members_only BOOLEAN DEFAULT FALSE NOT NULL');
        $this->addSql('ALTER TABLE news_article_revisions ADD thumbnail_object_id VARCHAR(64) DEFAULT NULL, ADD thumbnail_mime_type VARCHAR(32) DEFAULT NULL, ADD thumbnail_size INT DEFAULT NULL');
        $this->addSql("ALTER TABLE news_article_revisions ADD CONSTRAINT chk_news_article_revisions_thumbnail_metadata CHECK ((thumbnail_object_id IS NULL AND thumbnail_mime_type IS NULL AND thumbnail_size IS NULL) OR (thumbnail_object_id IS NOT NULL AND thumbnail_mime_type IS NOT NULL AND thumbnail_size IS NOT NULL AND thumbnail_object_id ~ '^[a-f0-9]{64}$' AND thumbnail_mime_type IN ('image/jpeg', 'image/png', 'image/webp') AND thumbnail_size BETWEEN 1 AND 4194304))");
        $this->addSql("ALTER TABLE static_pages ADD meta_keywords VARCHAR(500) DEFAULT NULL, ADD meta_description VARCHAR(500) DEFAULT NULL, ADD author_name VARCHAR(120) DEFAULT 'Redaktion' NOT NULL, ADD show_in_menu BOOLEAN DEFAULT FALSE NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('news_article_revisions', 'news_articles', 'static_pages');
        $dataAtRisk = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*) FROM (
                SELECT 1 FROM news_articles
                WHERE members_only IS TRUE
                    OR thumbnail_object_id IS NOT NULL
                    OR thumbnail_mime_type IS NOT NULL
                    OR thumbnail_size IS NOT NULL
                UNION ALL
                SELECT 1 FROM news_article_revisions
                WHERE members_only IS TRUE
                    OR thumbnail_object_id IS NOT NULL
                    OR thumbnail_mime_type IS NOT NULL
                    OR thumbnail_size IS NOT NULL
                UNION ALL
                SELECT 1 FROM static_pages
                WHERE meta_keywords IS NOT NULL
                    OR meta_description IS NOT NULL
                    OR author_name <> 'Redaktion'
                    OR show_in_menu IS TRUE
            ) AS migration_data_at_risk
            SQL);
        if (!in_array($dataAtRisk, [0, '0'], true)) {
            throw new \RuntimeException('Mitgliederbeschränkte News, Vorschaubilder und Seitenmetadaten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('ALTER TABLE static_pages DROP show_in_menu, DROP author_name, DROP meta_description, DROP meta_keywords');
        $this->addSql('ALTER TABLE news_article_revisions DROP CONSTRAINT chk_news_article_revisions_thumbnail_metadata');
        $this->addSql('ALTER TABLE news_article_revisions DROP thumbnail_size, DROP thumbnail_mime_type, DROP thumbnail_object_id');
        $this->addSql('ALTER TABLE news_article_revisions DROP members_only');
        $this->addSql('ALTER TABLE news_articles DROP CONSTRAINT chk_news_articles_thumbnail_metadata');
        $this->addSql('DROP INDEX uniq_news_articles_thumbnail_object_id');
        $this->addSql('ALTER TABLE news_articles DROP thumbnail_size, DROP thumbnail_mime_type, DROP thumbnail_object_id, DROP members_only');
    }
}
