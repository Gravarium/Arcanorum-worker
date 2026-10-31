<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt den Aktivierungsstatus für Mitgliedskonten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE member_accounts ADD is_active BOOLEAN DEFAULT TRUE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('member_accounts');
        $inactiveAccounts = $this->connection->fetchOne('SELECT COUNT(*) FROM member_accounts WHERE is_active IS FALSE');
        if (!in_array($inactiveAccounts, [0, '0'], true)) {
            throw new \RuntimeException('Deaktivierte Mitgliedskonten dürfen durch den Migrationsrückbau nicht versehentlich reaktiviert werden.');
        }

        $this->addSql('ALTER TABLE member_accounts DROP is_active');
    }
}
