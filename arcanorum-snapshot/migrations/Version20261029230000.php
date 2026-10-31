<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt private, validierte Website-Logos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD site_logo_object_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD site_logo_mime_type VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD site_logo_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE site_settings ADD site_logo_sha256 VARCHAR(64) DEFAULT NULL');
        $this->addSql(
            "ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_logo_metadata CHECK (
                (site_logo_object_id IS NULL AND site_logo_mime_type IS NULL AND site_logo_size IS NULL AND site_logo_sha256 IS NULL)
                OR (
                    site_logo_object_id IS NOT NULL
                    AND site_logo_mime_type IS NOT NULL
                    AND site_logo_size IS NOT NULL
                    AND site_logo_sha256 IS NOT NULL
                    AND site_logo_object_id ~ '^[a-f0-9]{64}$'
                    AND site_logo_mime_type = 'image/png'
                    AND site_logo_size BETWEEN 1 AND 524288
                    AND site_logo_sha256 ~ '^[a-f0-9]{64}$'
                )
            )",
        );
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $configured = $this->connection->fetchOne('SELECT COUNT(*) FROM site_settings WHERE site_logo_object_id IS NOT NULL');
        if (!in_array($configured, [0, '0'], true)) {
            throw new \RuntimeException('Ein konfiguriertes Website-Logo darf durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_logo_metadata');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN site_logo_object_id');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN site_logo_mime_type');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN site_logo_size');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN site_logo_sha256');
    }
}
