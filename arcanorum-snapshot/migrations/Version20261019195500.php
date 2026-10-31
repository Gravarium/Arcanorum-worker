<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261019195500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fügt ein geschütztes Kontaktpostfach für Nachrichten der Website hinzu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE contact_messages (id VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(254) NOT NULL, subject VARCHAR(160) NOT NULL, body TEXT NOT NULL, status VARCHAR(16) DEFAULT 'pending' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT chk_contact_messages_status CHECK (status IN ('pending', 'resolved')), CONSTRAINT chk_contact_messages_resolved_at CHECK ((status = 'pending' AND resolved_at IS NULL) OR (status = 'resolved' AND resolved_at IS NOT NULL)))");
        $this->addSql('CREATE INDEX idx_contact_messages_status_created ON contact_messages (status, created_at)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('contact_messages');
        $messages = $this->connection->fetchOne('SELECT COUNT(*) FROM contact_messages');
        if (!in_array($messages, [0, '0'], true)) {
            throw new \RuntimeException('Gespeicherte Kontaktnachrichten mit personenbezogenen Angaben müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP INDEX idx_contact_messages_status_created');
        $this->addSql('DROP TABLE contact_messages');
    }
}
