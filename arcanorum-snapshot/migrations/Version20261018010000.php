<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt erste News- und Videoverwaltungsdaten für den Adminbereich.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE news_articles (id VARCHAR(32) NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(200) NOT NULL, category VARCHAR(100) DEFAULT NULL, summary VARCHAR(500) DEFAULT NULL, body TEXT NOT NULL, status VARCHAR(16) NOT NULL, featured BOOLEAN DEFAULT FALSE NOT NULL, comments_enabled BOOLEAN DEFAULT TRUE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_news_articles_slug ON news_articles (slug)');
        $this->addSql('CREATE INDEX idx_news_articles_status_created ON news_articles (status, created_at)');
        $this->addSql('CREATE TABLE videos (id VARCHAR(32) NOT NULL, title VARCHAR(180) NOT NULL, description TEXT NOT NULL, category VARCHAR(100) DEFAULT NULL, source_type VARCHAR(16) NOT NULL, source_url VARCHAR(2048) NOT NULL, status VARCHAR(16) NOT NULL, featured BOOLEAN DEFAULT FALSE NOT NULL, comments_enabled BOOLEAN DEFAULT TRUE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_videos_status_created ON videos (status, created_at)');
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('news_articles', 'videos');
        $videos = $guard->countRows('SELECT COUNT(*) FROM videos');
        $newsArticles = $guard->countRows('SELECT COUNT(*) FROM news_articles');
        if ($videos > 0 || $newsArticles > 0) {
            throw new \RuntimeException('News und Videos müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE videos');
        $this->addSql('DROP TABLE news_articles');
    }
}
