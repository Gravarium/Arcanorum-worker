<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verwaltet gemeinsame Schlagwörter und deren News- und Videozuordnungen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE content_tags (id VARCHAR(32) NOT NULL, name VARCHAR(100) NOT NULL, normalized_name VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_content_tags_normalized_name ON content_tags (normalized_name)');
        $this->addSql('CREATE INDEX idx_content_tags_name ON content_tags (name)');

        $this->addSql('CREATE TABLE news_article_tags (article_id VARCHAR(32) NOT NULL, tag_id VARCHAR(32) NOT NULL, PRIMARY KEY(article_id, tag_id))');
        $this->addSql('CREATE INDEX idx_news_article_tags_tag ON news_article_tags (tag_id)');
        $this->addSql('ALTER TABLE news_article_tags ADD CONSTRAINT fk_news_article_tags_article FOREIGN KEY (article_id) REFERENCES news_articles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE news_article_tags ADD CONSTRAINT fk_news_article_tags_tag FOREIGN KEY (tag_id) REFERENCES content_tags (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE video_tags (video_id VARCHAR(32) NOT NULL, tag_id VARCHAR(32) NOT NULL, PRIMARY KEY(video_id, tag_id))');
        $this->addSql('CREATE INDEX idx_video_tags_tag ON video_tags (tag_id)');
        $this->addSql('ALTER TABLE video_tags ADD CONSTRAINT fk_video_tags_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE video_tags ADD CONSTRAINT fk_video_tags_tag FOREIGN KEY (tag_id) REFERENCES content_tags (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('content_tags', 'news_article_tags', 'video_tags');
        $tags = $guard->countRows('SELECT COUNT(*) FROM content_tags');
        $newsAssignments = $guard->countRows('SELECT COUNT(*) FROM news_article_tags');
        $videoAssignments = $guard->countRows('SELECT COUNT(*) FROM video_tags');
        if ($tags > 0 || $newsAssignments > 0 || $videoAssignments > 0) {
            throw new \RuntimeException('Schlagwörter und ihre News-/Videozuordnungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE video_tags');
        $this->addSql('DROP TABLE news_article_tags');
        $this->addSql('DROP TABLE content_tags');
    }
}
