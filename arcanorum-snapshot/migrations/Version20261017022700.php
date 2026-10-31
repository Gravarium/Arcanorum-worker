<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261017022700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widerruft bestehende Admin-Sitzungen nach Änderungen an MFA-Zugangsdaten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_users ADD mfa_auth_version INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('admin_users');
        $revokedSessions = $guard->countRows('SELECT COUNT(*) FROM admin_users WHERE mfa_auth_version <> 0');
        if ($revokedSessions > 0) {
            throw new \RuntimeException('Widerrufene Administratorsitzungen dürfen durch den Migrationsrückbau nicht wieder gültig werden.');
        }

        $this->addSql('ALTER TABLE admin_users DROP mfa_auth_version');
    }
}
