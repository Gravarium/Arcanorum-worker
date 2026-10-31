<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt gehashte, ablaufende Einrichtungscodes für Mitgliedskonten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE member_accounts ADD invitation_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE member_accounts ADD invitation_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_member_accounts_invitation_token_hash ON member_accounts (invitation_token_hash)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('member_accounts');
        $invitations = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM member_accounts WHERE invitation_token_hash IS NOT NULL OR invitation_expires_at IS NOT NULL',
        );
        if (!in_array($invitations, [0, '0'], true)) {
            throw new \RuntimeException('Mitgliedereinladungen müssen vor dem Migrationsrückbau erhalten oder neu ausgestellt werden.');
        }

        $this->addSql('DROP INDEX idx_member_accounts_invitation_token_hash');
        $this->addSql('ALTER TABLE member_accounts DROP invitation_expires_at');
        $this->addSql('ALTER TABLE member_accounts DROP invitation_token_hash');
    }
}
