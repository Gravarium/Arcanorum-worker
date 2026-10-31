<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt sichere Moderationszustände für öffentliche Mitgliederaktivitäten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE member_activity_moderations (id VARCHAR(32) NOT NULL, activity_type VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, video_submission_id VARCHAR(32) DEFAULT NULL, playlist_id VARCHAR(32) DEFAULT NULL, comment_id VARCHAR(32) DEFAULT NULL, moderator_id VARCHAR(32) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_member_activity_moderation_status CHECK (status IN ('visible', 'hidden', 'removed')), CONSTRAINT chk_member_activity_moderation_source CHECK ((activity_type = 'video_submission' AND video_submission_id IS NOT NULL AND playlist_id IS NULL AND comment_id IS NULL) OR (activity_type = 'playlist' AND video_submission_id IS NULL AND playlist_id IS NOT NULL AND comment_id IS NULL) OR (activity_type IN ('video_comment', 'news_comment') AND video_submission_id IS NULL AND playlist_id IS NULL AND comment_id IS NOT NULL)), CONSTRAINT uniq_member_activity_moderation_submission UNIQUE (video_submission_id), CONSTRAINT uniq_member_activity_moderation_playlist UNIQUE (playlist_id), CONSTRAINT uniq_member_activity_moderation_comment UNIQUE (comment_id))");
        $this->addSql('CREATE INDEX idx_member_activity_moderation_status ON member_activity_moderations (status, updated_at)');
        $this->addSql('ALTER TABLE member_activity_moderations ADD CONSTRAINT fk_member_activity_moderation_submission FOREIGN KEY (video_submission_id) REFERENCES video_submissions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE member_activity_moderations ADD CONSTRAINT fk_member_activity_moderation_playlist FOREIGN KEY (playlist_id) REFERENCES member_playlists (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE member_activity_moderations ADD CONSTRAINT fk_member_activity_moderation_comment FOREIGN KEY (comment_id) REFERENCES content_comments (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE member_activity_moderations ADD CONSTRAINT fk_member_activity_moderation_moderator FOREIGN KEY (moderator_id) REFERENCES admin_users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('member_activity_moderations');
        $moderations = $this->connection->fetchOne('SELECT COUNT(*) FROM member_activity_moderations');
        if (!in_array($moderations, [0, '0'], true)) {
            throw new \RuntimeException('Aktivitätsmoderationen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE member_activity_moderations');
    }
}
