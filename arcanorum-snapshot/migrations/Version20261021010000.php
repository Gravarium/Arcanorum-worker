<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt den geschützten Verwaltungsaktivitätsverlauf.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_activity_entries (id VARCHAR(32) NOT NULL, actor_id VARCHAR(32) DEFAULT NULL, route_name VARCHAR(128) NOT NULL, method VARCHAR(8) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_admin_activity_created ON admin_activity_entries (created_at)');
        $this->addSql('CREATE INDEX idx_admin_activity_actor ON admin_activity_entries (actor_id)');
        $this->addSql('ALTER TABLE admin_activity_entries ADD CONSTRAINT fk_admin_activity_actor FOREIGN KEY (actor_id) REFERENCES admin_users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('admin_activity_entries');
        $entries = $this->connection->fetchOne('SELECT COUNT(*) FROM admin_activity_entries');
        if (!in_array($entries, [0, '0'], true)) {
            throw new \RuntimeException('Der Verwaltungsaktivitätsverlauf muss vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE admin_activity_entries');
    }
}
