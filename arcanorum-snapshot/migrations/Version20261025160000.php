<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt öffentliche Autorennamen und geschützte News-Versionen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE news_articles ADD author_name VARCHAR(120) DEFAULT 'Redaktion' NOT NULL");
        $this->addSql("ALTER TABLE news_articles ADD CONSTRAINT chk_news_articles_author_name CHECK (length(btrim(author_name)) BETWEEN 1 AND 120 AND author_name !~ '[[:cntrl:]]')");
        $this->addSql('CREATE TABLE news_article_revisions (id VARCHAR(32) NOT NULL, article_id VARCHAR(32) NOT NULL, version INT NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(200) NOT NULL, category VARCHAR(100) DEFAULT NULL, summary VARCHAR(500) DEFAULT NULL, body TEXT NOT NULL, status VARCHAR(16) NOT NULL, featured BOOLEAN NOT NULL, comments_enabled BOOLEAN NOT NULL, author_name VARCHAR(120) NOT NULL, tag_names TEXT NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, editor_admin_id VARCHAR(32) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_news_article_revisions_version ON news_article_revisions (article_id, version)');
        $this->addSql('ALTER TABLE news_article_revisions ADD CONSTRAINT chk_news_article_revisions_version CHECK (version > 0)');
        $this->addSql("ALTER TABLE news_article_revisions ADD CONSTRAINT chk_news_article_revisions_status CHECK (status IN ('draft', 'published'))");
        $this->addSql("ALTER TABLE news_article_revisions ADD CONSTRAINT chk_news_article_revisions_author_name CHECK (length(btrim(author_name)) BETWEEN 1 AND 120 AND author_name !~ '[[:cntrl:]]')");
        $this->addSql('ALTER TABLE news_article_revisions ADD CONSTRAINT fk_news_article_revisions_article FOREIGN KEY (article_id) REFERENCES news_articles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE news_article_revisions ADD CONSTRAINT fk_news_article_revisions_editor FOREIGN KEY (editor_admin_id) REFERENCES admin_users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("INSERT INTO news_article_revisions (id, article_id, version, title, slug, category, summary, body, status, featured, comments_enabled, author_name, tag_names, published_at, editor_admin_id, created_at) SELECT article.id, article.id, 1, article.title, article.slug, article.category, article.summary, article.body, article.status, article.featured, article.comments_enabled, article.author_name, COALESCE((SELECT string_agg(tag.name, ', ' ORDER BY tag.normalized_name COLLATE \"C\") FROM news_article_tags relation INNER JOIN content_tags tag ON tag.id = relation.tag_id WHERE relation.article_id = article.id), ''), article.published_at, NULL, article.updated_at FROM news_articles article");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('news_article_revisions', 'news_articles');
        $customAuthors = $this->connection->fetchOne("SELECT COUNT(*) FROM news_articles WHERE author_name <> 'Redaktion'");
        if (!in_array($customAuthors, [0, '0'], true)) {
            throw new \RuntimeException('Konfigurierte News-Autoren dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        if ($rollbackGuard->hasRows('SELECT EXISTS (SELECT 1 FROM news_article_revisions LIMIT 1)')) {
            throw new \RuntimeException('Der News-Änderungsverlauf muss vor dem Migrationsrückbau gesichert oder entfernt werden.');
        }

        $this->addSql('DROP TABLE news_article_revisions');
        $this->addSql('ALTER TABLE news_articles DROP CONSTRAINT chk_news_articles_author_name');
        $this->addSql('ALTER TABLE news_articles DROP author_name');
    }
}
