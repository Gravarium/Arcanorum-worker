<?php

declare(strict_types=1);

namespace App\Module\Feed\Service;

use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Settings\Service\ModuleAvailability;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Repository\ContentSeriesRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\SeriesEpisodeRepository;
use App\Repository\StaticPageRepository;
use App\Repository\VideoRepository;
use App\Service\ExternalVideoEmbedResolver;

final readonly class SitemapDocumentBuilder implements SitemapDocumentBuilderInterface
{
    private const int BATCH_SIZE = 500;
    private const int MAX_URLS = 50000;
    private const int MAX_BYTES = 47185920;

    public function __construct(
        private CanonicalPublicUrlGenerator $publicUrls,
        private NewsArticleRepository $articles,
        private VideoRepository $videos,
        private StaticPageRepository $pages,
        private ContentSeriesRepository $series,
        private SeriesEpisodeRepository $episodes,
        private ModuleAvailability $modules,
        private ExternalVideoEmbedResolver $embedResolver,
        private VideoStreamSourceResolver $streamResolver,
    ) {
    }

    public function contentSitemap(): ?string
    {
        if (!$this->publicUrls->isConfigured()) {
            return null;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $this->appendEntry($xml, $this->publicUrls->generate('platform_home'), null);
        $remaining = self::MAX_URLS - 1;

        if ($this->modules->isEnabled('news')) {
            $offset = 0;
            do {
                $batch = $this->articles->findPublished(
                    limit: min(self::BATCH_SIZE, $remaining),
                    offset: $offset,
                    excludeMembersOnly: true,
                );
                foreach ($batch as $article) {
                    if (null !== $article->getPublishedAt()) {
                        $this->appendEntry(
                            $xml,
                            $this->publicUrls->generate('news_show', ['slug' => $article->getSlug()]),
                            $article->getPublishedAt(),
                        );
                    }
                }
                $batchSize = count($batch);
                $remaining -= $batchSize;
                $offset += $batchSize;
            } while (self::BATCH_SIZE === $batchSize && $remaining > 0);
        }

        if ($remaining > 0) {
            $offset = 0;
            do {
                $batch = $this->videos->findPublished(limit: min(self::BATCH_SIZE, $remaining), offset: $offset);
                foreach ($batch as $video) {
                    if (null !== $video->getPublishedAt()) {
                        $this->appendEntry(
                            $xml,
                            $this->publicUrls->generate('video_show', ['id' => $video->getId()]),
                            $video->getPublishedAt(),
                        );
                    }
                }
                $batchSize = count($batch);
                $remaining -= $batchSize;
                $offset += $batchSize;
            } while (self::BATCH_SIZE === $batchSize && $remaining > 0);
        }

        if ($remaining > 0 && $this->modules->isEnabled('pages')) {
            $offset = 0;
            do {
                $batch = $this->pages->findPublished(limit: min(self::BATCH_SIZE, $remaining), offset: $offset);
                foreach ($batch as $page) {
                    if (null !== $page->getPublishedAt()) {
                        $this->appendEntry(
                            $xml,
                            $this->publicUrls->generate('page_show', ['slug' => $page->getSlug()]),
                            $page->getPublishedAt(),
                        );
                    }
                }
                $batchSize = count($batch);
                $remaining -= $batchSize;
                $offset += $batchSize;
            } while (self::BATCH_SIZE === $batchSize && $remaining > 0);
        }

        if ($remaining > 0 && $this->modules->isEnabled('series')) {
            $offset = 0;
            do {
                $batch = $this->series->findPublished(limit: min(self::BATCH_SIZE, $remaining), offset: $offset);
                foreach ($batch as $series) {
                    if (null !== $series->getPublishedAt()) {
                        $this->appendEntry(
                            $xml,
                            $this->publicUrls->generate('series_show', ['slug' => $series->getSlug()]),
                            $series->getPublishedAt(),
                        );
                    }
                }
                $batchSize = count($batch);
                $remaining -= $batchSize;
                $offset += $batchSize;
            } while (self::BATCH_SIZE === $batchSize && $remaining > 0);
        }

        if ($remaining > 0 && $this->modules->isEnabled('series')) {
            $offset = 0;
            do {
                $batch = $this->episodes->findPublishedForSitemap(limit: min(self::BATCH_SIZE, $remaining), offset: $offset);
                foreach ($batch as $episode) {
                    $publishedAt = $episode->getVideo()->getPublishedAt();
                    if (null !== $publishedAt) {
                        $this->appendEntry(
                            $xml,
                            $this->publicUrls->generate('series_episode_show', [
                                'slug' => $episode->getSeries()->getSlug(),
                                'seasonNumber' => $episode->getSeasonNumber(),
                                'episodeNumber' => $episode->getEpisodeNumber(),
                            ]),
                            $publishedAt,
                        );
                    }
                }
                $batchSize = count($batch);
                $remaining -= $batchSize;
                $offset += $batchSize;
            } while (self::BATCH_SIZE === $batchSize && $remaining > 0);
        }

        return $xml.'</urlset>';
    }

    public function sitemapIndex(): ?string
    {
        if (!$this->publicUrls->isConfigured()) {
            return null;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (['content_sitemap', 'video_sitemap'] as $route) {
            $xml .= '<sitemap><loc>'.self::escapeXml($this->publicUrls->generate($route)).'</loc></sitemap>';
        }

        return $xml.'</sitemapindex>';
    }

    public function videoSitemap(): ?string
    {
        if (!$this->publicUrls->isConfigured()) {
            return null;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">';
        $offset = 0;
        $scanned = 0;
        $stop = false;

        do {
            $limit = min(self::BATCH_SIZE, self::MAX_URLS - $scanned);
            $batch = $this->videos->findPublished(limit: $limit, offset: $offset);
            foreach ($batch as $video) {
                $entry = $this->videoEntry($video);
                if (null === $entry) {
                    continue;
                }
                if (strlen($xml) + strlen($entry) + strlen('</urlset>') > self::MAX_BYTES) {
                    $stop = true;
                    break;
                }
                $xml .= $entry;
            }

            $batchSize = count($batch);
            $offset += $batchSize;
            $scanned += $batchSize;
        } while (!$stop && $batchSize === $limit && $scanned < self::MAX_URLS);

        return $xml.'</urlset>';
    }

    /** @return array{tag: 'content_loc'|'player_loc', url: string}|null */
    private function mediaLocationForVideo(Video $video): ?array
    {
        if (VideoSourceType::File === $video->getSourceType()) {
            $objectId = $video->getUploadedObjectId();
            $mimeType = $video->getUploadedMimeType();
            $size = $video->getUploadedSize();
            if (null === $objectId
                || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)
                || null === $mimeType
                || !in_array($mimeType, ['video/mp4', 'video/webm', 'video/ogg'], true)
                || null === $size
                || $size < 1
                || $size > 33554432
            ) {
                return null;
            }

            return ['tag' => 'content_loc', 'url' => $this->publicUrls->generate('video_media', ['id' => $video->getId()])];
        }

        if (VideoSourceType::Embed === $video->getSourceType()) {
            $playerUrl = $this->embedResolver->resolve($video->getSourceUrl());

            return null === $playerUrl ? null : ['tag' => 'player_loc', 'url' => $playerUrl];
        }

        if (VideoSourceType::Stream === $video->getSourceType()) {
            $source = $this->streamResolver->resolve($video->getSourceUrl());

            return null === $source ? null : ['tag' => 'content_loc', 'url' => $source->url];
        }

        return null;
    }

    private function videoEntry(Video $video): ?string
    {
        $thumbnailObjectId = $video->getThumbnailObjectId();
        $location = $this->mediaLocationForVideo($video);
        if (null === $thumbnailObjectId
            || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $thumbnailObjectId)
            || null === $location
        ) {
            return null;
        }

        $pageUrl = $this->publicUrls->generate('video_show', ['id' => $video->getId()]);
        $thumbnailUrl = $this->publicUrls->generate('video_thumbnail', ['id' => $video->getId()]);
        $title = mb_substr(trim($video->getTitle()), 0, 100, 'UTF-8');
        $description = trim(strip_tags($video->getDescription()));
        if ('' === $description) {
            $description = $title;
        }
        $description = mb_substr($description, 0, 2048, 'UTF-8');

        $xml = '<url><loc>'.self::escapeXml($pageUrl).'</loc>';
        $xml .= '<lastmod>'.$video->getUpdatedAt()->format('Y-m-d').'</lastmod>';
        $xml .= '<video:video>';
        $xml .= '<video:thumbnail_loc>'.self::escapeXml($thumbnailUrl).'</video:thumbnail_loc>';
        $xml .= '<video:title>'.self::escapeXml($title).'</video:title>';
        $xml .= '<video:description>'.self::escapeXml($description).'</video:description>';
        if ('player_loc' === $location['tag']) {
            $xml .= '<video:player_loc allow_embed="yes">'.self::escapeXml($location['url']).'</video:player_loc>';
        } else {
            $xml .= '<video:content_loc>'.self::escapeXml($location['url']).'</video:content_loc>';
        }
        if (null !== $video->getPublishedAt()) {
            $xml .= '<video:publication_date>'.$video->getPublishedAt()->format(DATE_ATOM).'</video:publication_date>';
        }

        return $xml.'</video:video></url>';
    }

    private function appendEntry(string &$xml, string $url, ?\DateTimeImmutable $lastModified): void
    {
        $xml .= '<url><loc>'.self::escapeXml($url).'</loc>';
        if (null !== $lastModified) {
            $xml .= '<lastmod>'.$lastModified->format('Y-m-d').'</lastmod>';
        }
        $xml .= '</url>';
    }

    private static function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
