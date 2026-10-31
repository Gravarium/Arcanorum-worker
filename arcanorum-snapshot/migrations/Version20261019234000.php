<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fügt getrennte Mitgliedskonten mit privaten Profilen als Standard hinzu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE member_accounts (id VARCHAR(32) NOT NULL, email VARCHAR(254) NOT NULL, handle VARCHAR(32) NOT NULL, display_name VARCHAR(80) NOT NULL, bio VARCHAR(500) DEFAULT NULL, password VARCHAR(255) NOT NULL, public_profile BOOLEAN DEFAULT FALSE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT uniq_member_accounts_email UNIQUE (email), CONSTRAINT uniq_member_accounts_handle UNIQUE (handle), CONSTRAINT chk_member_accounts_handle CHECK (handle ~ '^[a-z0-9][a-z0-9_-]{2,31}$'))");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('member_accounts');
        $accounts = $this->connection->fetchOne('SELECT COUNT(*) FROM member_accounts');
        if (!in_array($accounts, [0, '0'], true)) {
            throw new \RuntimeException('Mitgliedskonten und ihre personenbezogenen Daten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE member_accounts');
    }
}
