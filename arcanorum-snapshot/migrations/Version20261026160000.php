<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add allowlisted responsive maximum widths for Arcanorum video players.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD video_player_max_width VARCHAR(8) NOT NULL DEFAULT 'fluid'");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_video_player_max_width CHECK (video_player_max_width IN ('fluid', '640', '800', '1024'))");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $customizedWidths = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM site_settings WHERE video_player_max_width <> 'fluid'",
        );
        if (!in_array($customizedWidths, [0, '0'], true)) {
            throw new \RuntimeException('Die maximale Playerbreite darf durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_video_player_max_width');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN video_player_max_width');
    }
}
