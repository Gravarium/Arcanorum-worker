<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Indexes scheduled and published news by status and publication time.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_news_articles_status_published ON news_articles (status, published_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_news_articles_status_published');
    }
}
