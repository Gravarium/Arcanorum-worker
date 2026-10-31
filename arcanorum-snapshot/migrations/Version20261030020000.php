<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speichert bereinigte News- und Seitenbilder außerhalb des öffentlichen Webverzeichnisses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE content_inline_images (
                id VARCHAR(64) NOT NULL,
                news_article_id VARCHAR(32) DEFAULT NULL,
                static_page_id VARCHAR(32) DEFAULT NULL,
                mime_type VARCHAR(32) NOT NULL,
                size INT NOT NULL,
                sha256 VARCHAR(64) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT chk_inline_image_id CHECK (id ~ '^[a-f0-9]{64}$'),
                CONSTRAINT chk_inline_image_owner CHECK (
                    (news_article_id IS NOT NULL AND static_page_id IS NULL)
                    OR (news_article_id IS NULL AND static_page_id IS NOT NULL)
                ),
                CONSTRAINT chk_inline_image_mime CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp')),
                CONSTRAINT chk_inline_image_size CHECK (size BETWEEN 1 AND 4194304),
                CONSTRAINT chk_inline_image_sha256 CHECK (sha256 ~ '^[a-f0-9]{64}$'),
                PRIMARY KEY(id),
                CONSTRAINT fk_inline_image_news FOREIGN KEY (news_article_id) REFERENCES news_articles (id) ON DELETE CASCADE,
                CONSTRAINT fk_inline_image_page FOREIGN KEY (static_page_id) REFERENCES static_pages (id) ON DELETE CASCADE
            )
            SQL,
        );
        $this->addSql('CREATE INDEX idx_inline_images_news_owner ON content_inline_images (news_article_id)');
        $this->addSql('CREATE INDEX idx_inline_images_page_owner ON content_inline_images (static_page_id)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('content_inline_images');
        $imageCount = $this->connection->fetchOne('SELECT COUNT(*) FROM content_inline_images');
        if (!in_array($imageCount, [0, '0'], true)) {
            throw new \RuntimeException('Inline-Bilder müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE content_inline_images');
    }
}
