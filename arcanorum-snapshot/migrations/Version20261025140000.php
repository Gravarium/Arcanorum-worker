<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add privacy-preserving, member-scoped ratings for published videos and approved comments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE content_ratings (id VARCHAR(32) NOT NULL, member_id VARCHAR(32) NOT NULL, video_id VARCHAR(32) DEFAULT NULL, comment_id VARCHAR(32) DEFAULT NULL, value VARCHAR(8) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT fk_content_ratings_member FOREIGN KEY (member_id) REFERENCES member_accounts (id) ON DELETE CASCADE, CONSTRAINT fk_content_ratings_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE, CONSTRAINT fk_content_ratings_comment FOREIGN KEY (comment_id) REFERENCES content_comments (id) ON DELETE CASCADE, CONSTRAINT chk_content_ratings_target CHECK ((video_id IS NULL) <> (comment_id IS NULL)), CONSTRAINT chk_content_ratings_value CHECK (value IN ('like', 'dislike')), CONSTRAINT uniq_content_ratings_member_video UNIQUE (member_id, video_id), CONSTRAINT uniq_content_ratings_member_comment UNIQUE (member_id, comment_id))");
        $this->addSql('CREATE INDEX idx_content_ratings_video_value ON content_ratings (video_id, value)');
        $this->addSql('CREATE INDEX idx_content_ratings_comment_value ON content_ratings (comment_id, value)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('content_ratings');
        $ratings = $this->connection->fetchOne('SELECT COUNT(*) FROM content_ratings');
        if (!in_array($ratings, [0, '0'], true)) {
            throw new \RuntimeException('Mitgliedsbewertungen müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP INDEX idx_content_ratings_comment_value');
        $this->addSql('DROP INDEX idx_content_ratings_video_value');
        $this->addSql('DROP TABLE content_ratings');
    }
}
