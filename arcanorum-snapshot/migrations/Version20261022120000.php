<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MemberEmailChallengeMigrationGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt Einmal-Challenges für bestätigte Mitglieds-E-Mails und Passwort-Wiederherstellung.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE member_accounts ADD email_verified BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('ALTER TABLE member_accounts ADD email_version INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE member_accounts ADD CONSTRAINT chk_member_accounts_email_version CHECK (email_version >= 0)');
        $this->addSql('CREATE TABLE member_email_challenges (id VARCHAR(32) NOT NULL, member_id VARCHAR(32) NOT NULL, email_version INTEGER NOT NULL, purpose VARCHAR(32) NOT NULL, token_hash CHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, consumed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT fk_member_email_challenges_member FOREIGN KEY (member_id) REFERENCES member_accounts (id) ON DELETE CASCADE, CONSTRAINT chk_member_email_challenges_purpose CHECK (purpose IN (\'email_verification\', \'password_reset\')), CONSTRAINT chk_member_email_challenges_expiry CHECK (expires_at > created_at))');
        $this->addSql('CREATE UNIQUE INDEX uniq_member_email_challenges_token_hash ON member_email_challenges (token_hash)');
        $this->addSql('CREATE INDEX idx_member_email_challenges_member_purpose ON member_email_challenges (member_id, purpose, expires_at)');
        $this->addSql('CREATE TABLE member_security_rate_limits (key_hash CHAR(64) NOT NULL, window_started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, request_count INTEGER NOT NULL, PRIMARY KEY(key_hash), CONSTRAINT chk_member_security_rate_limits_count CHECK (request_count BETWEEN 1 AND 1000000))');
        $this->addSql('CREATE INDEX idx_member_security_rate_limits_window ON member_security_rate_limits (window_started_at)');
    }

    public function down(Schema $schema): void
    {
        new MemberEmailChallengeMigrationGuard($this->connection)->assertSafeToRemove();

        $this->addSql('DROP INDEX idx_member_email_challenges_member_purpose');
        $this->addSql('DROP INDEX uniq_member_email_challenges_token_hash');
        $this->addSql('DROP INDEX idx_member_security_rate_limits_window');
        $this->addSql('DROP TABLE member_security_rate_limits');
        $this->addSql('DROP TABLE member_email_challenges');
        $this->addSql('ALTER TABLE member_accounts DROP CONSTRAINT chk_member_accounts_email_version');
        $this->addSql('ALTER TABLE member_accounts DROP email_version');
        $this->addSql('ALTER TABLE member_accounts DROP email_verified');
    }
}
