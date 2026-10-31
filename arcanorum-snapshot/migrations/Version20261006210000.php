<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Legt die Administratorkonten für die geschützte Anmeldung an.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_users (id VARCHAR(32) NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_admin_users_email ON admin_users (email)');
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('admin_users');
        $adminUsers = $guard->countRows('SELECT COUNT(*) FROM admin_users');
        if ($adminUsers > 0) {
            throw new \RuntimeException('Administratorkonten und ihre Zugangsdaten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE admin_users');
    }
}
