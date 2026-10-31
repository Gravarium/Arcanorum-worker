<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fügt eine begrenzte Seitenlänge für öffentliche Kommentare hinzu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD comments_per_page SMALLINT NOT NULL DEFAULT 10');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_comments_per_page CHECK (comments_per_page BETWEEN 1 AND 100)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $customized = $this->connection->fetchOne('SELECT COUNT(*) FROM site_settings WHERE comments_per_page <> 10');
        if (!in_array($customized, [0, '0'], true)) {
            throw new \RuntimeException('Die konfigurierte Kommentarseitenlänge darf durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_comments_per_page');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN comments_per_page');
    }
}
