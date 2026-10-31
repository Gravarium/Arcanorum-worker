<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add private, attributable member bans with revocation history.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE member_accounts ADD auth_version INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE member_accounts ADD CONSTRAINT chk_member_accounts_auth_version CHECK (auth_version >= 0)');
        $this->addSql('CREATE TABLE member_account_bans (id VARCHAR(32) NOT NULL, member_id VARCHAR(32) NOT NULL, banned_by_admin_id VARCHAR(32) DEFAULT NULL, revoked_by_admin_id VARCHAR(32) DEFAULT NULL, reason VARCHAR(500) NOT NULL, was_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT fk_member_account_bans_member FOREIGN KEY (member_id) REFERENCES member_accounts (id) ON DELETE CASCADE, CONSTRAINT fk_member_account_bans_banned_by FOREIGN KEY (banned_by_admin_id) REFERENCES admin_users (id) ON DELETE SET NULL, CONSTRAINT fk_member_account_bans_revoked_by FOREIGN KEY (revoked_by_admin_id) REFERENCES admin_users (id) ON DELETE SET NULL, CONSTRAINT chk_member_account_bans_reason CHECK (char_length(btrim(reason)) BETWEEN 1 AND 500), CONSTRAINT chk_member_account_bans_revoked_at CHECK (revoked_at IS NULL OR revoked_at >= created_at))');
        $this->addSql('CREATE INDEX idx_member_account_bans_member_created ON member_account_bans (member_id, created_at)');
        $this->addSql('CREATE INDEX idx_member_account_bans_revoked_created ON member_account_bans (revoked_at, created_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_member_account_bans_one_active ON member_account_bans (member_id) WHERE revoked_at IS NULL');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('member_account_bans', 'member_accounts');
        $bans = $this->connection->fetchOne('SELECT COUNT(*) FROM member_account_bans');
        $revokedSessions = $this->connection->fetchOne('SELECT COUNT(*) FROM member_accounts WHERE auth_version <> 0');
        if (!in_array($bans, [0, '0'], true) || !in_array($revokedSessions, [0, '0'], true)) {
            throw new \RuntimeException('Mitgliedersperren und Authentifizierungswiderrufe müssen vor dem Migrationsrückbau erhalten bleiben.');
        }

        $this->addSql('DROP TABLE member_account_bans');
        $this->addSql('ALTER TABLE member_accounts DROP CONSTRAINT chk_member_accounts_auth_version');
        $this->addSql('ALTER TABLE member_accounts DROP COLUMN auth_version');
    }
}
