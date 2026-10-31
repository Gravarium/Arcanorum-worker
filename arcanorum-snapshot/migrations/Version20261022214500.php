<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022214500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt eine konfigurierbare, validierte Absenderadresse für E-Mails.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD email_from_address VARCHAR(254) NOT NULL DEFAULT ''");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $configured = $this->connection->fetchOne("SELECT COUNT(*) FROM site_settings WHERE email_from_address <> ''");
        if (!in_array($configured, [0, '0'], true)) {
            throw new \RuntimeException('Eine konfigurierte Absenderadresse darf durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP COLUMN email_from_address');
    }
}
