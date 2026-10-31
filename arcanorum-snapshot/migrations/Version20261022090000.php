<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt zeitlich begrenzte, aggregierte Suchstatistik ohne Nutzerkennungen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE search_term_daily (day DATE NOT NULL, term VARCHAR(100) NOT NULL, search_count INT NOT NULL CHECK (search_count > 0), last_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(day, term))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE search_term_daily');
    }
}
