<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261025150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store explicit HTTPS origins allowed to frame published video players.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE videos ADD embed_allowed_origins JSON DEFAULT '[]' NOT NULL");
        $this->addSql("ALTER TABLE videos ADD CONSTRAINT chk_videos_embed_allowed_origins_array CHECK (jsonb_typeof(embed_allowed_origins::jsonb) = 'array')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE videos DROP CONSTRAINT chk_videos_embed_allowed_origins_array');
        $this->addSql('ALTER TABLE videos DROP embed_allowed_origins');
    }
}
