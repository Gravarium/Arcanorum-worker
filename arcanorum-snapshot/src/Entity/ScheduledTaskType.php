<?php

declare(strict_types=1);

namespace App\Entity;

enum ScheduledTaskType: string
{
    case VideoSourceCheck = 'video_source_check';
    case ProviderImport = 'provider_import';
    case SitemapRefresh = 'sitemap_refresh';

    public function label(): string
    {
        return match ($this) {
            self::VideoSourceCheck => 'Externe Videoquellen prüfen',
            self::ProviderImport => 'Videos aus einem Anbieter importieren',
            self::SitemapRefresh => 'Öffentliche Sitemaps aktualisieren',
        };
    }

    public function isSystemOnly(): bool
    {
        return self::VideoSourceCheck === $this;
    }
}
