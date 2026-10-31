<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Führt datensparsame Mitgliedsbeziehungen für Folgen und Aktivitätsfeeds ein.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE member_follows (id VARCHAR(32) NOT NULL, follower_id VARCHAR(32) NOT NULL, followed_id VARCHAR(32) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT uniq_member_follows_pair UNIQUE (follower_id, followed_id), CONSTRAINT chk_member_follows_not_self CHECK (follower_id <> followed_id))');
        $this->addSql('CREATE INDEX idx_member_follows_followed_created ON member_follows (followed_id, created_at)');
        $this->addSql('CREATE INDEX idx_member_follows_follower_created ON member_follows (follower_id, created_at)');
        $this->addSql('ALTER TABLE member_follows ADD CONSTRAINT fk_member_follows_follower FOREIGN KEY (follower_id) REFERENCES member_accounts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE member_follows ADD CONSTRAINT fk_member_follows_followed FOREIGN KEY (followed_id) REFERENCES member_accounts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('member_follows');
        if ($rollbackGuard->hasRows('SELECT EXISTS (SELECT 1 FROM member_follows LIMIT 1)')) {
            throw new \RuntimeException('Mitgliedsbeziehungen müssen vor dem Migrationsrückbau exportiert oder entfernt werden.');
        }

        $this->addSql('DROP TABLE member_follows');
    }
}
