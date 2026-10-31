<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bindet sichere Rich-Text-Inlinebilder an News- und Videokategorien.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_inline_images ADD category_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_inline_images DROP CONSTRAINT chk_inline_image_owner');
        $this->addSql(<<<'SQL'
            ALTER TABLE content_inline_images
            ADD CONSTRAINT chk_inline_image_owner CHECK (
                (news_article_id IS NOT NULL AND static_page_id IS NULL AND category_id IS NULL)
                OR (news_article_id IS NULL AND static_page_id IS NOT NULL AND category_id IS NULL)
                OR (news_article_id IS NULL AND static_page_id IS NULL AND category_id IS NOT NULL)
            )
            SQL,
        );
        $this->addSql('ALTER TABLE content_inline_images ADD CONSTRAINT fk_inline_image_category FOREIGN KEY (category_id) REFERENCES content_categories (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_inline_images_category_owner ON content_inline_images (category_id)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('content_inline_images');
        $categoryImageCount = $this->connection->fetchOne('SELECT COUNT(*) FROM content_inline_images WHERE category_id IS NOT NULL');
        if (!in_array($categoryImageCount, [0, '0'], true)) {
            throw new \RuntimeException('Kategorie-Inline-Bilder müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP INDEX idx_inline_images_category_owner');
        $this->addSql('ALTER TABLE content_inline_images DROP CONSTRAINT fk_inline_image_category');
        $this->addSql('ALTER TABLE content_inline_images DROP CONSTRAINT chk_inline_image_owner');
        $this->addSql('ALTER TABLE content_inline_images DROP COLUMN category_id');
        $this->addSql(<<<'SQL'
            ALTER TABLE content_inline_images
            ADD CONSTRAINT chk_inline_image_owner CHECK (
                (news_article_id IS NOT NULL AND static_page_id IS NULL)
                OR (news_article_id IS NULL AND static_page_id IS NOT NULL)
            )
            SQL,
        );
    }
}
