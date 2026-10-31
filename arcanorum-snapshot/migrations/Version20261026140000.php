<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add separate Facebook and Twitter social-login switches.';
    }

    public function up(Schema $schema): void
    {
        $unknownIdentities = $this->connection->fetchOne("SELECT COUNT(*) FROM member_external_identities WHERE provider NOT IN ('google', 'facebook', 'twitter')");
        if (!in_array($unknownIdentities, [0, '0'], true)) {
            throw new \RuntimeException('Unbekannte externe Anmeldedienste müssen vor der Migration geprüft werden.');
        }

        $this->addSql('ALTER TABLE site_settings ADD social_facebook_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE site_settings ADD social_twitter_enabled BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql("ALTER TABLE member_external_identities ADD CONSTRAINT chk_member_external_provider CHECK (provider IN ('google', 'facebook', 'twitter'))");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('member_external_identities', 'site_settings');
        $enabled = $this->connection->fetchOne('SELECT COUNT(*) FROM site_settings WHERE social_facebook_enabled IS TRUE OR social_twitter_enabled IS TRUE');
        if (!in_array($enabled, [0, '0'], true)) {
            throw new \RuntimeException('Aktivierte soziale Anmeldedienste dürfen durch den Migrationsrückbau nicht abgeschaltet werden.');
        }
        $linkedIdentities = $this->connection->fetchOne("SELECT COUNT(*) FROM member_external_identities WHERE provider IN ('facebook', 'twitter')");
        if (!in_array($linkedIdentities, [0, '0'], true)) {
            throw new \RuntimeException('Verknüpfte Facebook- und Twitter-Identitäten dürfen durch den Migrationsrückbau nicht unbrauchbar werden.');
        }

        $this->addSql('ALTER TABLE member_external_identities DROP CONSTRAINT chk_member_external_provider');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN social_facebook_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN social_twitter_enabled');
    }
}
