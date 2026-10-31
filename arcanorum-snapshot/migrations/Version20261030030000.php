<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt die schaltbare Teilnahme nicht angemeldeter Gäste an Kommentaren.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD guest_comments_enabled BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $disabled = $this->connection->fetchOne('SELECT COUNT(*) FROM site_settings WHERE guest_comments_enabled = FALSE');
        if (!in_array($disabled, [0, '0'], true)) {
            throw new \RuntimeException('Die Gästekommentareinstellung darf durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP COLUMN guest_comments_enabled');
    }
}
