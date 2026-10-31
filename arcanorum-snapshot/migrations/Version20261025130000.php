<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt native sowie einwilligungsgeschützte Facebook- und Disqus-Kommentare.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE site_settings ADD comment_provider VARCHAR(16) NOT NULL DEFAULT 'native'");
        $this->addSql("ALTER TABLE site_settings ADD facebook_comments_app_id VARCHAR(32) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE site_settings ADD disqus_comments_shortname VARCHAR(63) NOT NULL DEFAULT ''");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_comment_provider CHECK (comment_provider IN ('native', 'facebook', 'disqus'))");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_facebook_comments_app_id CHECK (facebook_comments_app_id = '' OR facebook_comments_app_id ~ '^[0-9]{5,32}$')");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_disqus_comments_shortname CHECK (disqus_comments_shortname = '' OR disqus_comments_shortname ~ '^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$')");
        $this->addSql("ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_comment_provider_config CHECK ((comment_provider <> 'facebook' OR facebook_comments_app_id <> '') AND (comment_provider <> 'disqus' OR disqus_comments_shortname <> '') AND (comment_provider = 'native' OR cookie_consent_enabled IS TRUE))");
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $configured = $this->connection->fetchOne("SELECT COUNT(*) FROM site_settings WHERE comment_provider <> 'native' OR facebook_comments_app_id <> '' OR disqus_comments_shortname <> ''");
        if (!in_array($configured, [0, '0'], true)) {
            throw new \RuntimeException('Konfigurierte externe Kommentar-Anbieter müssen vor dem Migrationsrückbau deaktiviert werden.');
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_comment_provider_config');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_disqus_comments_shortname');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_facebook_comments_app_id');
        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_comment_provider');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN disqus_comments_shortname');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN facebook_comments_app_id');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN comment_provider');
    }
}
