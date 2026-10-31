<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store optional validated player watermark images in private object storage.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD video_player_watermark_object_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD video_player_watermark_mime_type VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD video_player_watermark_size INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD video_player_watermark_sha256 VARCHAR(64) DEFAULT NULL');
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_video_player_watermark_asset CHECK (
            (video_player_watermark_object_id IS NULL
                AND video_player_watermark_mime_type IS NULL
                AND video_player_watermark_size IS NULL
                AND video_player_watermark_sha256 IS NULL)
            OR
            (video_player_watermark_object_id ~ '^[a-f0-9]{64}$'
                AND video_player_watermark_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
                AND video_player_watermark_size BETWEEN 1 AND 2097152
                AND video_player_watermark_sha256 ~ '^[a-f0-9]{64}$')
        )");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $storedWatermarks = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM site_settings WHERE video_player_watermark_object_id IS NOT NULL',
        );
        if (!in_array($storedWatermarks, [0, '0'], true)) {
            throw new \RuntimeException('Gespeicherte Wasserzeichen müssen vor dem Migrationsrückbau entfernt werden.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_video_player_watermark_asset');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN video_player_watermark_sha256');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN video_player_watermark_size');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN video_player_watermark_mime_type');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN video_player_watermark_object_id');
    }
}
