<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt private Vorschaubilder für Videos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE videos ADD thumbnail_object_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE videos ADD thumbnail_mime_type VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE videos ADD thumbnail_size INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_videos_thumbnail_object_id ON videos (thumbnail_object_id)');
        $this->addSql("ALTER TABLE videos ADD CONSTRAINT chk_videos_thumbnail_metadata CHECK ((thumbnail_object_id IS NULL AND thumbnail_mime_type IS NULL AND thumbnail_size IS NULL) OR (thumbnail_object_id IS NOT NULL AND thumbnail_mime_type IS NOT NULL AND thumbnail_size IS NOT NULL AND thumbnail_object_id ~ '^[a-f0-9]{64}$' AND thumbnail_mime_type IN ('image/jpeg', 'image/png', 'image/webp') AND thumbnail_size BETWEEN 1 AND 4194304))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE videos DROP CONSTRAINT chk_videos_thumbnail_metadata');
        $this->addSql('DROP INDEX uniq_videos_thumbnail_object_id');
        $this->addSql('ALTER TABLE videos DROP thumbnail_size');
        $this->addSql('ALTER TABLE videos DROP thumbnail_mime_type');
        $this->addSql('ALTER TABLE videos DROP thumbnail_object_id');
    }
}
