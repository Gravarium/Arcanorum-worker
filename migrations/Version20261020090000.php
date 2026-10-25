<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261020090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ordnet Videoeinreichungen optional einem Mitgliedskonto zu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_submissions ADD submitter_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_video_submissions_submitter_created ON video_submissions (submitter_id, created_at)');
        $this->addSql('ALTER TABLE video_submissions ADD CONSTRAINT fk_video_submissions_submitter FOREIGN KEY (submitter_id) REFERENCES member_accounts (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE video_submissions DROP CONSTRAINT fk_video_submissions_submitter');
        $this->addSql('DROP INDEX idx_video_submissions_submitter_created');
        $this->addSql('ALTER TABLE video_submissions DROP submitter_id');
    }
}
