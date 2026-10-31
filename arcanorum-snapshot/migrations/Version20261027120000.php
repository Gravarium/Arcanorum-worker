<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Beschränkt Video-Vorabwerbung auf Videoformate und jeweils eine aktive Anzeige.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE cms_advertising_placement ADD CONSTRAINT chk_advertising_video_preroll_format CHECK (id <> 'video-player-preroll' OR formats = '[\"video\"]'::jsonb)");
        $this->addSql("ALTER TABLE cms_advertising_ad ADD CONSTRAINT chk_advertising_video_preroll_ad_format CHECK (placement_id <> 'video-player-preroll' OR format = 'video')");
        $this->addSql("CREATE UNIQUE INDEX uniq_advertising_active_video_preroll ON cms_advertising_ad (placement_id) WHERE placement_id = 'video-player-preroll' AND status = 'active'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_advertising_active_video_preroll');
        $this->addSql('ALTER TABLE cms_advertising_ad DROP CONSTRAINT chk_advertising_video_preroll_ad_format');
        $this->addSql('ALTER TABLE cms_advertising_placement DROP CONSTRAINT chk_advertising_video_preroll_format');
    }
}
