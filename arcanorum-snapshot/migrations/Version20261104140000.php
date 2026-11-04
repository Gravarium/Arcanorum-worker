<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt redaktionelle Video-Permalinks, Zugriffsoptionen und Veröffentlichungsmetadaten.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE videos ADD slug VARCHAR(200) DEFAULT '' NOT NULL");
        $this->addSql("UPDATE videos SET slug = 'video-' || id WHERE slug = ''");
        $this->addSql('CREATE UNIQUE INDEX uniq_videos_slug ON videos (slug)');
        $this->addSql("ALTER TABLE videos ADD CONSTRAINT chk_videos_slug CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
        $this->addSql("ALTER TABLE videos ADD author_name VARCHAR(120) DEFAULT 'Redaktion' NOT NULL");
        $this->addSql('ALTER TABLE videos ADD language_code VARCHAR(35) DEFAULT NULL');
        $this->addSql("ALTER TABLE videos ADD CONSTRAINT chk_videos_language_code CHECK (language_code IS NULL OR language_code ~ '^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$')");
        $this->addSql("ALTER TABLE videos ADD members_only BOOLEAN DEFAULT FALSE NOT NULL");
        $this->addSql("ALTER TABLE videos ADD embedding_enabled BOOLEAN DEFAULT FALSE NOT NULL");
        $this->addSql('UPDATE videos SET embedding_enabled = TRUE WHERE jsonb_array_length(embed_allowed_origins::jsonb) > 0');
    }

    public function down(Schema $schema): void
    {
        $guard = new MigrationRollbackGuard($this->connection);
        $guard->lockTables('videos');
        $customized = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM videos WHERE slug <> ('video-' || id) OR author_name <> 'Redaktion' OR language_code IS NOT NULL OR members_only OR (embedding_enabled AND jsonb_array_length(embed_allowed_origins::jsonb) = 0)",
        );
        if (!in_array($customized, [0, '0'], true)) {
            throw new \RuntimeException('Redaktionelle Video-Metadaten dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE videos DROP CONSTRAINT chk_videos_slug');
        $this->addSql('DROP INDEX uniq_videos_slug');
        $this->addSql('ALTER TABLE videos DROP slug');
        $this->addSql('ALTER TABLE videos DROP CONSTRAINT chk_videos_language_code');
        $this->addSql('ALTER TABLE videos DROP language_code');
        $this->addSql('ALTER TABLE videos DROP author_name');
        $this->addSql('ALTER TABLE videos DROP members_only');
        $this->addSql('ALTER TABLE videos DROP embedding_enabled');
    }
}
