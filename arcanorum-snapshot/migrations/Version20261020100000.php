<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt den Aktivierungsstatus für Mitgliedskonten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE member_accounts ADD is_active BOOLEAN DEFAULT TRUE NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE member_accounts DROP is_active');
    }
}
