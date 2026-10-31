<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030050500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erlaubt geprüfte MP3-Dateien in der privaten Video-Medienspalte.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE videos DROP CONSTRAINT chk_videos_source_metadata');
        $this->addSql('ALTER TABLE videos ADD CONSTRAINT chk_videos_source_metadata CHECK ((source_type = \'file\' AND source_url = \'\' AND uploaded_object_id IS NOT NULL AND uploaded_mime_type IN (\'video/mp4\', \'video/webm\', \'video/ogg\', \'audio/mpeg\') AND uploaded_size > 0 AND uploaded_size <= 33554432) OR (source_type IN (\'url\', \'stream\', \'embed\') AND source_url <> \'\' AND uploaded_object_id IS NULL AND uploaded_mime_type IS NULL AND uploaded_size IS NULL))');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('videos');
        $audioUploads = $rollbackGuard->countRows(
            "SELECT COUNT(*) FROM videos WHERE source_type = 'file' AND uploaded_mime_type = 'audio/mpeg'",
        );
        if ($audioUploads > 0) {
            throw new \RuntimeException('MP3-Uploads dürfen durch den Migrationsrückbau nicht ungültig werden.');
        }

        $this->addSql('ALTER TABLE videos DROP CONSTRAINT chk_videos_source_metadata');
        $this->addSql('ALTER TABLE videos ADD CONSTRAINT chk_videos_source_metadata CHECK ((source_type = \'file\' AND source_url = \'\' AND uploaded_object_id IS NOT NULL AND uploaded_mime_type IN (\'video/mp4\', \'video/webm\', \'video/ogg\') AND uploaded_size > 0 AND uploaded_size <= 33554432) OR (source_type IN (\'url\', \'stream\', \'embed\') AND source_url <> \'\' AND uploaded_object_id IS NULL AND uploaded_mime_type IS NULL AND uploaded_size IS NULL))');
    }
}
