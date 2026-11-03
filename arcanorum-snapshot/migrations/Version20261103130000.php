<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speichert die Auswahl hostabhängiger Sicherheitsfunktionen getrennt vom Pflichtschutz.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE hosting_security_selection (
                id SMALLINT NOT NULL,
                selected_feature_ids JSON NOT NULL DEFAULT '[]',
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_hosting_security_selection PRIMARY KEY (id),
                CONSTRAINT chk_hosting_security_selection_singleton CHECK (id = 1),
                CONSTRAINT chk_hosting_security_selection_array CHECK (json_typeof(selected_feature_ids) = 'array'),
                CONSTRAINT chk_hosting_security_selection_features CHECK (
                    selected_feature_ids::jsonb <@ '["private_storage","database_role_separation","private_redis_endpoint","process_isolation","outbound_network_policy","secret_store","verified_backup_restore"]'::jsonb
                )
            )
            SQL);
        $this->addSql("INSERT INTO hosting_security_selection (id, selected_feature_ids) VALUES (1, '[]'::json)");
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('hosting_security_selection');
        $selectedRows = $guard->countRows(
            "SELECT COUNT(*) FROM hosting_security_selection WHERE selected_feature_ids::jsonb <> '[]'::jsonb",
            failureMessage: 'Die Host-Sicherheitsauswahl konnte vor dem Migrationsrückbau nicht zuverlässig geprüft werden.',
        );
        if ($selectedRows > 0) {
            throw new \RuntimeException('Ausgewählte Host-Sicherheitsfunktionen müssen vor dem Migrationsrückbau erhalten oder ausdrücklich entfernt werden.');
        }

        $this->addSql('DROP TABLE hosting_security_selection');
    }
}
