<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022210500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fügt die abschaltbare Google-Anmeldung und verifizierte externe Mitgliedsidentitäten hinzu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD social_google_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('CREATE TABLE member_external_identities (id VARCHAR(32) NOT NULL, member_id VARCHAR(32) NOT NULL, provider VARCHAR(16) NOT NULL, subject VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT fk_member_external_identities_member FOREIGN KEY (member_id) REFERENCES member_accounts (id) ON DELETE CASCADE)');
        $this->addSql('CREATE UNIQUE INDEX uniq_member_external_provider_subject ON member_external_identities (provider, subject)');
        $this->addSql('CREATE UNIQUE INDEX uniq_member_external_member_provider ON member_external_identities (member_id, provider)');
        $this->addSql('CREATE INDEX idx_member_external_member ON member_external_identities (member_id)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('member_external_identities', 'site_settings');
        $linkedIdentities = $this->connection->fetchOne('SELECT COUNT(*) FROM member_external_identities');
        if (!in_array($linkedIdentities, [0, '0'], true)) {
            throw new \RuntimeException('Verknüpfte Mitgliedsidentitäten dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $enabled = $this->connection->fetchOne('SELECT COUNT(*) FROM site_settings WHERE social_google_enabled IS NOT FALSE');
        if (!in_array($enabled, [0, '0'], true)) {
            throw new \RuntimeException('Eine aktivierte Google-Anmeldung darf durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('DROP TABLE member_external_identities');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN social_google_enabled');
    }
}
