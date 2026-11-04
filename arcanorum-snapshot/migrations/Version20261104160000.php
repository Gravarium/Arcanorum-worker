<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Entity\ContentCategoryType;
use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Erweitert Videos um mehrere redaktionelle Kategorien und übernimmt bisherige Zuordnungen.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE video_categories (video_id VARCHAR(32) NOT NULL, category_id VARCHAR(32) NOT NULL, PRIMARY KEY(video_id, category_id))');
        $this->addSql('CREATE INDEX idx_video_categories_category ON video_categories (category_id)');
        $this->addSql('ALTER TABLE video_categories ADD CONSTRAINT fk_video_categories_video FOREIGN KEY (video_id) REFERENCES videos (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE video_categories ADD CONSTRAINT fk_video_categories_category FOREIGN KEY (category_id) REFERENCES content_categories (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql(
            'INSERT INTO video_categories (video_id, category_id) '
            .'SELECT video.id, category.id FROM videos AS video '
            .'INNER JOIN content_categories AS category '
            .'ON category.content_type = :content_type '
            .'AND (category.normalized_name = LOWER(BTRIM(video.category)) OR category.name = BTRIM(video.category)) '
            .'WHERE video.category IS NOT NULL AND BTRIM(video.category) <> \'\'',
            ['content_type' => ContentCategoryType::Video->value],
        );
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('videos', 'content_categories', 'video_categories');
        $unrepresentedAssignments = $guard->countRows(<<<'SQL'
            SELECT COUNT(*)
            FROM video_categories AS assignment
            INNER JOIN videos AS video ON video.id = assignment.video_id
            INNER JOIN content_categories AS category ON category.id = assignment.category_id
            WHERE BTRIM(COALESCE(video.category, '')) <> category.name
                AND LOWER(BTRIM(COALESCE(video.category, ''))) <> category.normalized_name
            SQL,
            failureMessage: 'Mehrfachzuordnungen konnten vor dem Migrationsrückbau nicht zuverlässig geprüft werden.',
        );
        if ($unrepresentedAssignments > 0) {
            throw new \RuntimeException('Zusätzliche Video-Kategorien müssen vor dem Migrationsrückbau erhalten oder exportiert werden.');
        }

        $this->addSql('DROP TABLE video_categories');
    }
}
