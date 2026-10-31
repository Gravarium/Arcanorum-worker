<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261018080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt Seriengenres und eigene Folgen-Metadaten mit Veröffentlichungsstatus.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_series ADD genres JSON DEFAULT \'[]\' NOT NULL');
        $this->addSql('ALTER TABLE content_series ADD CONSTRAINT chk_content_series_genres_array CHECK (json_typeof(genres) = \'array\')');

        $this->addSql('ALTER TABLE series_episodes ADD title_override VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE series_episodes ADD description_override TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE series_episodes ADD status VARCHAR(16) DEFAULT \'published\' NOT NULL');
        $this->addSql('ALTER TABLE series_episodes ADD CONSTRAINT chk_series_episodes_status CHECK (status IN (\'draft\', \'published\'))');
        $this->addSql('ALTER TABLE series_episodes ADD CONSTRAINT chk_series_episodes_title_override CHECK (title_override IS NULL OR char_length(title_override) BETWEEN 1 AND 180)');
        $this->addSql('ALTER TABLE series_episodes ADD CONSTRAINT chk_series_episodes_description_override CHECK (description_override IS NULL OR char_length(description_override) <= 20000)');
        $this->addSql('CREATE INDEX idx_series_episodes_visibility_order ON series_episodes (series_id, status, season_number, episode_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_series_episodes_visibility_order');
        $this->addSql('ALTER TABLE series_episodes DROP CONSTRAINT chk_series_episodes_description_override');
        $this->addSql('ALTER TABLE series_episodes DROP CONSTRAINT chk_series_episodes_title_override');
        $this->addSql('ALTER TABLE series_episodes DROP CONSTRAINT chk_series_episodes_status');
        $this->addSql('ALTER TABLE series_episodes DROP status');
        $this->addSql('ALTER TABLE series_episodes DROP description_override');
        $this->addSql('ALTER TABLE series_episodes DROP title_override');

        $this->addSql('ALTER TABLE content_series DROP CONSTRAINT chk_content_series_genres_array');
        $this->addSql('ALTER TABLE content_series DROP genres');
    }
}
