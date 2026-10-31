<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261022195000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt ausfallsicher konfigurierbare integrierte Inhaltsmodule.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE site_settings ADD module_news_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE site_settings ADD module_series_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE site_settings ADD module_comments_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE site_settings ADD module_contact_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE site_settings ADD module_pages_enabled BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('ALTER TABLE site_settings ADD module_search_enabled BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $customizedModules = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM site_settings WHERE module_news_enabled IS NOT TRUE OR module_series_enabled IS NOT TRUE OR module_comments_enabled IS NOT TRUE OR module_contact_enabled IS NOT TRUE OR module_pages_enabled IS NOT TRUE OR module_search_enabled IS NOT TRUE',
        );
        if (!in_array($customizedModules, [0, '0'], true)) {
            throw new \RuntimeException('Konfigurierte Modulabschaltungen dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $this->addSql('ALTER TABLE site_settings DROP COLUMN module_news_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN module_series_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN module_comments_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN module_contact_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN module_pages_enabled');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN module_search_enabled');
    }
}
