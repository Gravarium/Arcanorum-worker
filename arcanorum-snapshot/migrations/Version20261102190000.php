<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speichert anwendungsweite Login-Drosselungsfenster unabhängig von optionalem Redis.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE authentication_login_rate_limits (key_hash CHAR(64) NOT NULL, window_started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, request_count INTEGER NOT NULL, PRIMARY KEY(key_hash), CONSTRAINT chk_authentication_login_rate_limits_count CHECK (request_count BETWEEN 0 AND 26))');
        $this->addSql('CREATE INDEX idx_authentication_login_rate_limits_window ON authentication_login_rate_limits (window_started_at)');
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('authentication_login_rate_limits');
        $activeWindows = $guard->countRows(
            'SELECT COUNT(*) FROM authentication_login_rate_limits WHERE request_count > 0 AND window_started_at > :cutoff',
            ['cutoff' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-15 minutes')->format('Y-m-d H:i:s')],
            'Aktive Anmeldedrosselungsdaten konnten vor dem Migrationsrückbau nicht zuverlässig geprüft werden.',
        );
        if ($activeWindows > 0) {
            throw new \RuntimeException('Aktive Login-Drosselungsdaten müssen vor dem Rückbau ablaufen.');
        }

        $this->addSql('DROP INDEX idx_authentication_login_rate_limits_window');
        $this->addSql('DROP TABLE authentication_login_rate_limits');
    }
}
