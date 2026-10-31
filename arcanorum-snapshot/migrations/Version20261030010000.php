<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt begrenzte benutzerdefinierte Video- und News-Metafelder.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE videos ADD custom_fields JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE news_articles ADD custom_fields JSON NOT NULL DEFAULT '[]'");
        $this->addSql("ALTER TABLE news_article_revisions ADD custom_fields JSON NOT NULL DEFAULT '[]'");
        foreach (['videos', 'news_articles', 'news_article_revisions'] as $table) {
            $this->addSql(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT chk_%s_custom_fields_array CHECK (
                    CASE WHEN jsonb_typeof(custom_fields::jsonb) = 'array'
                    THEN jsonb_array_length(custom_fields::jsonb) <= 30 AND pg_column_size(custom_fields::jsonb) <= 32768
                    ELSE FALSE END
                )",
                $table,
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('news_article_revisions', 'news_articles', 'videos');
        foreach (['videos', 'news_articles', 'news_article_revisions'] as $table) {
            $configured = $this->connection->fetchOne(sprintf(
                "SELECT COUNT(*) FROM %s WHERE custom_fields::jsonb <> '[]'::jsonb",
                $table,
            ));
            if (!in_array($configured, [0, '0'], true)) {
                throw new \RuntimeException('Benutzerdefinierte Metafelder dürfen durch den Migrationsrückbau nicht verloren gehen.');
            }
        }

        $this->addSql('ALTER TABLE videos DROP CONSTRAINT chk_videos_custom_fields_array');
        $this->addSql('ALTER TABLE news_articles DROP CONSTRAINT chk_news_articles_custom_fields_array');
        $this->addSql('ALTER TABLE news_article_revisions DROP CONSTRAINT chk_news_article_revisions_custom_fields_array');
        $this->addSql('ALTER TABLE videos DROP COLUMN custom_fields');
        $this->addSql('ALTER TABLE news_articles DROP COLUMN custom_fields');
        $this->addSql('ALTER TABLE news_article_revisions DROP COLUMN custom_fields');
    }
}
