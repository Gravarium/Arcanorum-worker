<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MemberCommentMigrationGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ordnet neue Mitgliedskommentare ihrem Konto für eine private Aktivitätsansicht zu.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_comments ADD member_id VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_comments ADD CONSTRAINT fk_content_comments_member FOREIGN KEY (member_id) REFERENCES member_accounts (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_content_comments_member_created ON content_comments (member_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        (new MemberCommentMigrationGuard($this->connection))->assertNoAttributedComments();

        $this->addSql('ALTER TABLE content_comments DROP CONSTRAINT fk_content_comments_member');
        $this->addSql('DROP INDEX idx_content_comments_member_created');
        $this->addSql('ALTER TABLE content_comments DROP member_id');
    }
}
