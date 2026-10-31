<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt Sortierung und Schwelle für abgewertete öffentliche Kommentare.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD comment_default_sort VARCHAR(16) NOT NULL DEFAULT 'added'");
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_comment_default_sort CHECK (comment_default_sort IN (\'added\', \'score\'))');
        $this->addSql('ALTER TABLE site_settings ADD comment_rating_hide_threshold SMALLINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_comment_mute_threshold CHECK (comment_rating_hide_threshold BETWEEN 0 AND 1000)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $customized = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM site_settings WHERE comment_default_sort <> 'added' OR comment_rating_hide_threshold <> 0",
        );
        if (!in_array($customized, [0, '0'], true)) {
            throw new \RuntimeException('Kommentarsortierung und Abwertungsschwelle dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_comment_mute_threshold');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_comment_default_sort');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN comment_rating_hide_threshold');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN comment_default_sort');
    }
}
