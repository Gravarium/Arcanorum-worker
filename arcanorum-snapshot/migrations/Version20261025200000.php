<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Führt private Mitgliedslisten und eine begrenzte Videoaufruf-Historie ein.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE member_saved_videos (id VARCHAR(32) NOT NULL, member_id VARCHAR(32) NOT NULL, video_id VARCHAR(32) NOT NULL, collection VARCHAR(16) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_member_saved_videos_collection CHECK (collection IN ('watch_later', 'favorite')), CONSTRAINT uniq_member_saved_videos_collection UNIQUE (member_id, video_id, collection))");
        $this->addSql('CREATE INDEX idx_member_saved_videos_member_collection ON member_saved_videos (member_id, collection, created_at)');
        $this->addSql('ALTER TABLE member_saved_videos ADD CONSTRAINT fk_member_saved_videos_member FOREIGN KEY (member_id) REFERENCES member_accounts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE member_saved_videos ADD CONSTRAINT fk_member_saved_videos_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE member_video_history (id VARCHAR(32) NOT NULL, member_id VARCHAR(32) NOT NULL, video_id VARCHAR(32) NOT NULL, last_watched_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT uniq_member_video_history_pair UNIQUE (member_id, video_id))');
        $this->addSql('CREATE INDEX idx_member_video_history_member_watched ON member_video_history (member_id, last_watched_at)');
        $this->addSql('ALTER TABLE member_video_history ADD CONSTRAINT fk_member_video_history_member FOREIGN KEY (member_id) REFERENCES member_accounts (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE member_video_history ADD CONSTRAINT fk_member_video_history_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('member_saved_videos', 'member_video_history');
        foreach (['member_saved_videos', 'member_video_history'] as $table) {
            if ($rollbackGuard->hasRows(sprintf('SELECT EXISTS (SELECT 1 FROM %s LIMIT 1)', $table))) {
                throw new \RuntimeException('Private Mitgliedslisten und Verlauf müssen vor dem Migrationsrückbau exportiert oder entfernt werden.');
            }
        }

        $this->addSql('DROP TABLE member_video_history');
        $this->addSql('DROP TABLE member_saved_videos');
    }
}
