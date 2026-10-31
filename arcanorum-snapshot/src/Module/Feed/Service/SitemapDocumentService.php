<?php

declare(strict_types=1);

namespace App\Module\Feed\Service;

use Psr\Cache\CacheItemPoolInterface;

final readonly class SitemapDocumentService
{
    private const string CONTENT_KEY = 'arcanorum.public-content-sitemap.v1';
    private const string VIDEO_KEY = 'arcanorum.public-video-sitemap.v1';
    private const int CACHE_TTL_SECONDS = 600;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private SitemapDocumentBuilderInterface $builder,
        private SitemapCacheRevisionProvider $revisions,
        private CanonicalPublicUrlGenerator $publicUrls,
    ) {
    }

    /** @phpstan-impure */
    public function contentSitemap(): ?string
    {
        return $this->getCached($this->cacheKey(self::CONTENT_KEY), $this->builder->contentSitemap(...));
    }

    public function videoSitemap(): ?string
    {
        return $this->getCached($this->cacheKey(self::VIDEO_KEY), $this->builder->videoSitemap(...));
    }

    public function sitemapIndex(): ?string
    {
        return $this->builder->sitemapIndex();
    }

    public function refresh(): void
    {
        $revision = $this->revisions->current();
        $contentKey = $this->cacheKey(self::CONTENT_KEY, $revision);
        $videoKey = $this->cacheKey(self::VIDEO_KEY, $revision);
        $content = $this->builder->contentSitemap();
        $videos = $this->builder->videoSitemap();
        if (null === $content || null === $videos) {
            throw new \RuntimeException('Die öffentliche Basisadresse für Sitemaps ist nicht konfiguriert.');
        }

        $contentItem = $this->cache->getItem($contentKey);
        $contentItem->set($content);
        $contentItem->expiresAfter(self::CACHE_TTL_SECONDS);
        $videoItem = $this->cache->getItem($videoKey);
        $videoItem->set($videos);
        $videoItem->expiresAfter(self::CACHE_TTL_SECONDS);

        $contentDeferred = $this->cache->saveDeferred($contentItem);
        $videoDeferred = $this->cache->saveDeferred($videoItem);
        $committed = $this->cache->commit();
        if (!$contentDeferred || !$videoDeferred || !$committed) {
            throw new \RuntimeException('Die Sitemap-Zwischenspeicherung konnte nicht abgeschlossen werden.');
        }
    }

    /** @param callable(): ?string $build */
    private function getCached(string $key, callable $build): ?string
    {
        try {
            $item = $this->cache->getItem($key);
            $cached = $item->get();
            if ($item->isHit() && is_string($cached)) {
                return $cached;
            }

            $document = $build();
            if (null === $document) {
                return null;
            }

            $item->set($document);
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            $this->cache->save($item);

            return $document;
        } catch (\Psr\Cache\CacheException) {
            return $build();
        }
    }

    private function cacheKey(string $baseKey, ?string $revision = null): string
    {
        $origin = $this->publicUrls->isConfigured() ? $this->publicUrls->generate('platform_home') : '';
        $revision ??= $this->revisions->current();
        $revision = hash('sha256', $revision."\n".$origin);

        return $baseKey.'.'.$revision;
    }
}
