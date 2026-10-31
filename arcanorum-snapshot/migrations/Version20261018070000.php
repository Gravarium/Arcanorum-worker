<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt begrenzte, private Video-Datei-Uploads.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE videos ADD uploaded_object_id VARCHAR(128) DEFAULT NULL');
        $this->addSql('ALTER TABLE videos ADD uploaded_mime_type VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE videos ADD uploaded_size INT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_videos_uploaded_object_id ON videos (uploaded_object_id)');
        $this->addSql('ALTER TABLE videos ADD CONSTRAINT chk_videos_source_metadata CHECK ((source_type = \'file\' AND source_url = \'\' AND uploaded_object_id IS NOT NULL AND uploaded_mime_type IN (\'video/mp4\', \'video/webm\', \'video/ogg\') AND uploaded_size > 0 AND uploaded_size <= 33554432) OR (source_type IN (\'url\', \'stream\', \'embed\') AND source_url <> \'\' AND uploaded_object_id IS NULL AND uploaded_mime_type IS NULL AND uploaded_size IS NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE videos DROP CONSTRAINT chk_videos_source_metadata');
        $this->addSql('DROP INDEX uniq_videos_uploaded_object_id');
        $this->addSql('ALTER TABLE videos DROP uploaded_object_id');
        $this->addSql('ALTER TABLE videos DROP uploaded_mime_type');
        $this->addSql('ALTER TABLE videos DROP uploaded_size');
    }
}
