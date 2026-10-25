<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Speichert öffentliche News- und Videokommentare mit Freigabe- und Meldezustand.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE content_comments (id VARCHAR(32) NOT NULL, content_type VARCHAR(16) NOT NULL, content_id VARCHAR(32) NOT NULL, content_title VARCHAR(180) NOT NULL, author_name VARCHAR(80) NOT NULL, body TEXT NOT NULL, status VARCHAR(16) NOT NULL, report_count INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, moderated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id), CONSTRAINT chk_content_comments_type CHECK (content_type IN ('news', 'video')), CONSTRAINT chk_content_comments_status CHECK (status IN ('pending', 'approved', 'reported', 'rejected')), CONSTRAINT chk_content_comments_report_count CHECK (report_count >= 0))");
        $this->addSql('CREATE INDEX idx_content_comments_status_created ON content_comments (status, created_at)');
        $this->addSql('CREATE INDEX idx_content_comments_target_status ON content_comments (content_type, content_id, status)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE content_comments');
    }
}
