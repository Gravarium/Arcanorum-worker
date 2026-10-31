<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022205000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt eine verlustfreie Steuerung öffentlicher Mitgliedsregistrierungen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD member_registration_enabled BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $registrationDisabled = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM site_settings WHERE member_registration_enabled IS NOT TRUE',
        );
        if (!in_array($registrationDisabled, [0, '0'], true)) {
            throw new \RuntimeException('Eine deaktivierte Registrierung darf durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP COLUMN member_registration_enabled');
    }
}
