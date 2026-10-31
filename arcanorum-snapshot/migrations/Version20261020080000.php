<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fügt private und öffentliche Mitglieder-Wiedergabelisten mit Videozuordnung hinzu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE member_playlists (id VARCHAR(32) NOT NULL, owner_id VARCHAR(32) NOT NULL, name VARCHAR(80) NOT NULL, description VARCHAR(500) DEFAULT NULL, is_public BOOLEAN DEFAULT FALSE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_member_playlists_owner_updated ON member_playlists (owner_id, updated_at)');
        $this->addSql('ALTER TABLE member_playlists ADD CONSTRAINT fk_member_playlists_owner FOREIGN KEY (owner_id) REFERENCES member_accounts (id) ON DELETE CASCADE');

        $this->addSql('CREATE TABLE playlist_items (id VARCHAR(32) NOT NULL, playlist_id VARCHAR(32) NOT NULL, video_id VARCHAR(32) NOT NULL, position INT NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT uniq_playlist_items_playlist_video UNIQUE (playlist_id, video_id))');
        $this->addSql('CREATE INDEX idx_playlist_items_playlist_position ON playlist_items (playlist_id, position)');
        $this->addSql('ALTER TABLE playlist_items ADD CONSTRAINT fk_playlist_items_playlist FOREIGN KEY (playlist_id) REFERENCES member_playlists (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE playlist_items ADD CONSTRAINT fk_playlist_items_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('member_playlists', 'playlist_items');
        $playlists = $this->connection->fetchOne('SELECT COUNT(*) FROM member_playlists');
        $playlistItems = $this->connection->fetchOne('SELECT COUNT(*) FROM playlist_items');
        if (!in_array($playlists, [0, '0'], true) || !in_array($playlistItems, [0, '0'], true)) {
            throw new \RuntimeException('Private Wiedergabelisten und ihre Videozuordnungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE playlist_items');
        $this->addSql('DROP TABLE member_playlists');
    }
}
