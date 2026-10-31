<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speichert fehlgeschlagene externe Quellenprüfungen, ohne den letzten bekannten Status zu verlieren.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE videos ADD external_source_check_failed BOOLEAN DEFAULT FALSE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('videos');
        $failedChecks = $guard->countRows(
            'SELECT COUNT(*) FROM videos WHERE external_source_check_failed IS TRUE',
            failureMessage: 'Der Providerstatus konnte vor dem Migrationsrückbau nicht zuverlässig geprüft werden.',
        );
        if ($failedChecks > 0) {
            throw new \RuntimeException('Fehlgeschlagene Providerprüfungen müssen vor dem Migrationsrückbau erhalten oder erneut geprüft werden.');
        }

        $this->addSql('ALTER TABLE videos DROP external_source_check_failed');
    }
}
