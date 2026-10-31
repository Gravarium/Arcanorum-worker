<?php

declare(strict_types=1);

namespace App\Module\Feed\Service;

use Doctrine\DBAL\Connection;

final readonly class SitemapCacheRevisionProvider
{
    public function __construct(private Connection $connection)
    {
    }

    public function current(): string
    {
        $revision = $this->connection->fetchOne(
            'SELECT generation || \'-\' || revision::text FROM sitemap_content_revision WHERE id = 1',
        );
        if (!is_string($revision) || 1 !== preg_match('/\A[a-f0-9]{32}-(?:0|[1-9][0-9]{0,19})\z/D', $revision)) {
            throw new \RuntimeException('Die Sitemap-Revision konnte nicht sicher ermittelt werden.');
        }

        return $revision;
    }
}
