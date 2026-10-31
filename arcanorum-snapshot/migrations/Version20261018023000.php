<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018023000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erstellt veröffentlichbare statische Inhaltsseiten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE static_pages (id VARCHAR(32) NOT NULL, title VARCHAR(180) NOT NULL, slug VARCHAR(200) NOT NULL, summary VARCHAR(500) DEFAULT NULL, body TEXT NOT NULL, status VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_static_pages_slug ON static_pages (slug)');
        $this->addSql('CREATE INDEX idx_static_pages_status_published ON static_pages (status, published_at)');
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('static_pages');
        $pages = $guard->countRows('SELECT COUNT(*) FROM static_pages');
        if ($pages > 0) {
            throw new \RuntimeException('Statische Inhaltsseiten müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE static_pages');
    }
}
