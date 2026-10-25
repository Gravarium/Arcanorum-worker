<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt private Coverbilder für Serien.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_series ADD artwork_object_id VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_series ADD artwork_mime_type VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_series ADD artwork_size INT DEFAULT NULL');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_content_series_artwork_object_id ON content_series (artwork_object_id)',
        );
        $this->addSql(
            "ALTER TABLE content_series ADD CONSTRAINT chk_content_series_artwork_metadata CHECK (".
            '(artwork_object_id IS NULL AND artwork_mime_type IS NULL AND artwork_size IS NULL) OR '.
            '(artwork_object_id IS NOT NULL AND artwork_mime_type IS NOT NULL AND artwork_size IS NOT NULL '.
            "AND artwork_object_id ~ '^[a-f0-9]{64}$' ".
            "AND artwork_mime_type IN ('image/jpeg', 'image/png', 'image/webp') ".
            'AND artwork_size BETWEEN 1 AND 4194304))',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_series DROP CONSTRAINT chk_content_series_artwork_metadata');
        $this->addSql('DROP INDEX uniq_content_series_artwork_object_id');
        $this->addSql('ALTER TABLE content_series DROP artwork_size');
        $this->addSql('ALTER TABLE content_series DROP artwork_mime_type');
        $this->addSql('ALTER TABLE content_series DROP artwork_object_id');
    }
}
