<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt Videokategorien um hervorgehobene Startseitenbereiche und geprüftes Artwork.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_categories ADD is_featured BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE content_categories ADD artwork_object_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_categories ADD artwork_mime_type VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_categories ADD artwork_size INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE content_categories ADD artwork_sha256 VARCHAR(64) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE content_categories
            ADD CONSTRAINT chk_content_categories_featured_type
            CHECK (content_type = 'video' OR is_featured = FALSE)
            SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE content_categories
            ADD CONSTRAINT chk_content_categories_artwork
            CHECK (
                num_nonnulls(artwork_object_id, artwork_mime_type, artwork_size, artwork_sha256) = 0
                OR (
                    num_nonnulls(artwork_object_id, artwork_mime_type, artwork_size, artwork_sha256) = 4
                    AND
                    content_type = 'video'
                    AND artwork_object_id ~ '^[a-f0-9]{64}$'
                    AND artwork_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
                    AND artwork_size BETWEEN 1 AND 1048576
                    AND artwork_sha256 ~ '^[a-f0-9]{64}$'
                )
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('content_categories');
        $customized = $this->connection->fetchOne(<<<'SQL'
            SELECT COUNT(*)
            FROM content_categories
            WHERE is_featured
               OR artwork_object_id IS NOT NULL
               OR artwork_mime_type IS NOT NULL
               OR artwork_size IS NOT NULL
               OR artwork_sha256 IS NOT NULL
            SQL);
        if (!in_array($customized, [0, '0'], true)) {
            $this->abortIf(true, 'Cannot remove featured category data or artwork during migration rollback.');
        }

        $this->addSql('ALTER TABLE content_categories DROP CONSTRAINT chk_content_categories_artwork');
        $this->addSql('ALTER TABLE content_categories DROP CONSTRAINT chk_content_categories_featured_type');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN artwork_sha256');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN artwork_size');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN artwork_mime_type');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN artwork_object_id');
        $this->addSql('ALTER TABLE content_categories DROP COLUMN is_featured');
    }
}
