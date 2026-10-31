<?php

declare(strict_types=1);

namespace App\Module\Feed\Service;

interface SitemapDocumentBuilderInterface
{
    public function contentSitemap(): ?string;

    public function sitemapIndex(): ?string;

    public function videoSitemap(): ?string;
}
