<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261016130200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt TOTP-MFA und einmalige Wiederherstellungscodes für Administratorkonten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_users ADD mfa_secret_ciphertext VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE admin_users ADD mfa_last_totp_counter BIGINT DEFAULT NULL');
        $this->addSql('CREATE TABLE admin_user_recovery_codes (admin_user_id VARCHAR(32) NOT NULL, code_hash CHAR(64) NOT NULL, PRIMARY KEY(admin_user_id, code_hash), CONSTRAINT fk_admin_user_recovery_codes_admin_user FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE CASCADE)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_user_recovery_codes');
        $this->addSql('ALTER TABLE admin_users DROP mfa_last_totp_counter');
        $this->addSql('ALTER TABLE admin_users DROP mfa_secret_ciphertext');
    }
}
