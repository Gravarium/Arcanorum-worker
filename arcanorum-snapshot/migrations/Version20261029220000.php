<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Service\MigrationRollbackGuard;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt sichere Farben und Inhaltsbreite für öffentliche Themes.';
    }

    public function up(Schema $schema): void
    {
        $defaults = [
            'theme_heading_color' => '#182033',
            'theme_content_background_color' => '#FFFFFF',
            'theme_content_text_color' => '#182033',
            'theme_body_background_color' => '#F3F5F9',
            'theme_header_background_color' => '#FFFFFF',
            'theme_header_pane_color' => '#596783',
            'theme_navigation_background_color' => '#FFFFFF',
            'theme_navigation_link_color' => '#24365E',
            'theme_content_link_color' => '#24365E',
            'theme_video_link_color' => '#182033',
            'theme_site_title_color' => '#172236',
            'theme_footer_background_color' => '#F3F5F9',
            'theme_footer_link_color' => '#596783',
        ];

        foreach ($defaults as $column => $default) {
            $this->addSql(sprintf(
                "ALTER TABLE site_settings ADD %s VARCHAR(7) NOT NULL DEFAULT '%s'",
                $column,
                $default,
            ));
            $this->addSql(sprintf(
                "ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_%s CHECK (%s ~ '^#[0-9A-Fa-f]{6}$')",
                $column,
                $column,
            ));
        }

        $this->addSql('ALTER TABLE site_settings ADD theme_content_width INTEGER NOT NULL DEFAULT 1248');
        $this->addSql('ALTER TABLE site_settings ADD CONSTRAINT chk_site_settings_theme_content_width CHECK (theme_content_width BETWEEN 640 AND 1920)');
    }

    public function down(Schema $schema): void
    {
        $rollbackGuard = new MigrationRollbackGuard($this->connection);
        $rollbackGuard->lockTables('site_settings');
        $configured = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM site_settings
             WHERE theme_heading_color <> '#182033'
                OR theme_content_background_color <> '#FFFFFF'
                OR theme_content_text_color <> '#182033'
                OR theme_body_background_color <> '#F3F5F9'
                OR theme_header_background_color <> '#FFFFFF'
                OR theme_header_pane_color <> '#596783'
                OR theme_navigation_background_color <> '#FFFFFF'
                OR theme_navigation_link_color <> '#24365E'
                OR theme_content_link_color <> '#24365E'
                OR theme_video_link_color <> '#182033'
                OR theme_site_title_color <> '#172236'
                OR theme_footer_background_color <> '#F3F5F9'
                OR theme_footer_link_color <> '#596783'
                OR theme_content_width <> 1248",
        );
        if (!in_array($configured, [0, '0'], true)) {
            throw new \RuntimeException('Abweichende öffentliche Farben oder Inhaltsbreiten dürfen durch den Migrationsrückbau nicht verloren gehen.');
        }

        $columns = [
            'theme_heading_color',
            'theme_content_background_color',
            'theme_content_text_color',
            'theme_body_background_color',
            'theme_header_background_color',
            'theme_header_pane_color',
            'theme_navigation_background_color',
            'theme_navigation_link_color',
            'theme_content_link_color',
            'theme_video_link_color',
            'theme_site_title_color',
            'theme_footer_background_color',
            'theme_footer_link_color',
        ];

        foreach ($columns as $column) {
            $this->addSql(sprintf('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_%s', $column));
            $this->addSql(sprintf('ALTER TABLE site_settings DROP COLUMN %s', $column));
        }

        $this->addSql('ALTER TABLE site_settings DROP CONSTRAINT chk_site_settings_theme_content_width');
        $this->addSql('ALTER TABLE site_settings DROP COLUMN theme_content_width');
    }
}
