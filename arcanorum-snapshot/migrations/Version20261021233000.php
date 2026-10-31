<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt datensparsame Aufrufzahlen für beliebte Videos.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE videos ADD view_count INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_videos_status_views ON videos (status, view_count, published_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_videos_status_views');
        $this->addSql('ALTER TABLE videos DROP view_count');
    }
}
