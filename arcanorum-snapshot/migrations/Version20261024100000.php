<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ergänzt Anzeigenverwaltung, datensparsame Auslieferungsstatistik und abgesicherte Klickzählung.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE cms_advertising_placement (
            id varchar(64) PRIMARY KEY CHECK (id ~ '^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$'),
            label varchar(120) NOT NULL CHECK (length(trim(label)) BETWEEN 1 AND 120),
            formats jsonb NOT NULL CHECK (CASE WHEN jsonb_typeof(formats) = 'array' THEN jsonb_array_length(formats) BETWEEN 1 AND 3 AND formats <@ '[\"html_banner\", \"static_player\", \"video\"]'::jsonb ELSE false END),
            archived boolean NOT NULL DEFAULT false,
            paused boolean NOT NULL DEFAULT false
        )");
        $this->addSql("CREATE TABLE cms_advertising_ad (
            id char(32) PRIMARY KEY CHECK (id ~ '^[a-f0-9]{32}$'),
            label varchar(120) NOT NULL CHECK (length(trim(label)) BETWEEN 1 AND 120),
            placement_id varchar(64) NOT NULL REFERENCES cms_advertising_placement (id),
            format varchar(32) NOT NULL CHECK (format IN ('html_banner', 'static_player', 'video')),
            creative jsonb NOT NULL CHECK (jsonb_typeof(creative) = 'object'),
            destination_url text NOT NULL CHECK (destination_url LIKE 'https://%'),
            status varchar(16) NOT NULL CHECK (status IN ('draft', 'active', 'paused', 'inactive', 'archived')),
            created_at timestamptz NOT NULL,
            updated_at timestamptz NOT NULL
        )");
        $this->addSql('CREATE INDEX idx_cms_advertising_ad_placement_status ON cms_advertising_ad (placement_id, status)');
        $this->addSql("CREATE TABLE cms_advertising_daily_stats (
            day date NOT NULL,
            advertisement_id char(32) NOT NULL CHECK (advertisement_id ~ '^[a-f0-9]{32}$'),
            placement_id varchar(64) NOT NULL CHECK (placement_id ~ '^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$'),
            impressions bigint NOT NULL DEFAULT 0 CHECK (impressions >= 0),
            clicks bigint NOT NULL DEFAULT 0 CHECK (clicks >= 0),
            PRIMARY KEY (day, advertisement_id)
        )");
        $this->addSql('CREATE INDEX idx_cms_advertising_stats_placement_day ON cms_advertising_daily_stats (placement_id, day DESC)');
        $this->addSql("CREATE TABLE cms_advertising_click_dedupe (
            event_digest char(64) PRIMARY KEY CHECK (event_digest ~ '^[a-f0-9]{64}$'),
            expires_at timestamptz NOT NULL
        )");
        $this->addSql('CREATE INDEX idx_cms_advertising_click_dedupe_expiry ON cms_advertising_click_dedupe (expires_at)');
        $this->addSql("CREATE TABLE cms_advertising_click_window (
            advertisement_id char(32) NOT NULL CHECK (advertisement_id ~ '^[a-f0-9]{32}$'),
            minute_at timestamptz NOT NULL,
            click_count integer NOT NULL CHECK (click_count BETWEEN 1 AND 600),
            PRIMARY KEY (advertisement_id, minute_at)
        )");
        $this->addSql("CREATE TABLE cms_advertising_asset (
            id char(32) PRIMARY KEY CHECK (id ~ '^[a-f0-9]{32}$'),
            storage_object_id char(64) NOT NULL UNIQUE CHECK (storage_object_id ~ '^[a-f0-9]{64}$'),
            kind varchar(8) NOT NULL CHECK (kind IN ('image', 'video')),
            mime_type varchar(32) NOT NULL CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp', 'video/mp4')),
            size integer NOT NULL CHECK (size BETWEEN 1 AND 33554432),
            created_at timestamptz NOT NULL,
            CHECK ((kind = 'image' AND mime_type IN ('image/jpeg', 'image/png', 'image/webp') AND size <= 4194304)
                OR (kind = 'video' AND mime_type = 'video/mp4'))
        )");
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'cms_advertising_placement',
            'cms_advertising_ad',
            'cms_advertising_daily_stats',
            'cms_advertising_click_dedupe',
            'cms_advertising_click_window',
            'cms_advertising_asset',
        ] as $table) {
            $containsRows = $this->connection->fetchOne(sprintf('SELECT EXISTS (SELECT 1 FROM %s LIMIT 1)', $table));
            if (in_array($containsRows, [true, 1, '1', 't', 'true'], true)) {
                throw new \RuntimeException('Anzeigen- und Statistikdaten müssen vor dem Migrationsrückbau gesichert oder entfernt werden.');
            }
        }

        $this->addSql('DROP TABLE cms_advertising_asset');
        $this->addSql('DROP TABLE cms_advertising_click_window');
        $this->addSql('DROP TABLE cms_advertising_click_dedupe');
        $this->addSql('DROP TABLE cms_advertising_daily_stats');
        $this->addSql('DROP TABLE cms_advertising_ad');
        $this->addSql('DROP TABLE cms_advertising_placement');
    }
}
