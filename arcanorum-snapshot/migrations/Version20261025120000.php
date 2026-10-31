<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fügt private, geprüfte WebVTT-Sprachspuren für veröffentlichte Videos hinzu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE video_subtitle_tracks (
            id varchar(32) PRIMARY KEY CHECK (id ~ '^[a-f0-9]{32}$'),
            video_id varchar(32) NOT NULL REFERENCES videos (id) ON DELETE CASCADE,
            language_code varchar(35) NOT NULL CHECK (language_code ~ '^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$'),
            label varchar(120) NOT NULL CHECK (length(trim(label)) BETWEEN 1 AND 120),
            storage_object_id varchar(64) NOT NULL UNIQUE CHECK (storage_object_id ~ '^[a-f0-9]{64}$'),
            size integer NOT NULL CHECK (size BETWEEN 1 AND 1048576),
            sha256 varchar(64) NOT NULL CHECK (sha256 ~ '^[a-f0-9]{64}$'),
            created_at timestamp(0) without time zone NOT NULL,
            updated_at timestamp(0) without time zone NOT NULL,
            CONSTRAINT uniq_video_subtitle_language UNIQUE (video_id, language_code)
        )");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('video_subtitle_tracks');
        if ($rollbackGuard->hasRows('SELECT EXISTS (SELECT 1 FROM video_subtitle_tracks LIMIT 1)')) {
            throw new \RuntimeException('Untertitelspuren müssen vor dem Migrationsrückbau gesichert oder entfernt werden.');
        }

        $this->addSql('DROP TABLE video_subtitle_tracks');
    }
}
