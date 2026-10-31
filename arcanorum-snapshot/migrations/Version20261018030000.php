<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verwaltet Kategorien für News und Videos und übernimmt vorhandene Werte.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE content_categories (id VARCHAR(32) NOT NULL, name VARCHAR(100) NOT NULL, normalized_name VARCHAR(100) NOT NULL, content_type VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_content_categories_type_normalized_name ON content_categories (content_type, normalized_name)');
        $this->addSql('CREATE INDEX idx_content_categories_type_name ON content_categories (content_type, name)');
        $this->addSql("INSERT INTO content_categories (id, name, normalized_name, content_type, created_at, updated_at) SELECT md5('news:' || normalized_name), MIN(display_name), normalized_name, 'news', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM (SELECT BTRIM(category) AS display_name, LOWER(BTRIM(category)) AS normalized_name FROM news_articles WHERE category IS NOT NULL AND BTRIM(category) <> '') AS existing_news_categories GROUP BY normalized_name");
        $this->addSql("INSERT INTO content_categories (id, name, normalized_name, content_type, created_at, updated_at) SELECT md5('video:' || normalized_name), MIN(display_name), normalized_name, 'video', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM (SELECT BTRIM(category) AS display_name, LOWER(BTRIM(category)) AS normalized_name FROM videos WHERE category IS NOT NULL AND BTRIM(category) <> '') AS existing_video_categories GROUP BY normalized_name");
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('content_categories');
        $categories = $guard->countRows('SELECT COUNT(*) FROM content_categories');
        if ($categories > 0) {
            throw new \RuntimeException('Verwaltete Inhaltskategorien müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE content_categories');
    }
}
