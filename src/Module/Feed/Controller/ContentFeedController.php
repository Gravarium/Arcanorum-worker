<?php

declare(strict_types=1);

namespace App\Module\Feed\Controller;

use App\Entity\NewsArticle;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use App\Module\Settings\Service\ModuleAvailability;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Repository\ContentSeriesRepository;
use App\Repository\NewsArticleRepository;
use App\Repository\SeriesEpisodeRepository;
use App\Repository\StaticPageRepository;
use App\Repository\VideoRepository;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\SiteSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ContentFeedController extends AbstractController
{
    private const int SITEMAP_BATCH_SIZE = 500;
    private const int MAX_SITEMAP_URLS = 50000;
    private const int MAX_SITEMAP_BYTES = 47185920;
    private const int RSS_ITEM_LIMIT = 50;

    public function __construct(
        private readonly CanonicalPublicUrlGenerator $publicUrls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/news.rss', name: 'news_feed', methods: ['GET'])]
    public function newsFeed(
        NewsArticleRepository $articles,
        SiteSettingsProvider $siteSettings,
        ModuleAvailability $modules,
    ): Response {
        if (!$modules->isEnabled('news')) {
            return new Response('', Response::HTTP_NOT_FOUND, [
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        if (!$this->publicUrls->isConfigured()) {
            return $this->canonicalUrlUnavailable();
        }

        $site = $siteSettings->current();

        return $this->rss(
            $site->getSiteName().' News',
            $this->feedDescription(
                $site->getTagline(),
                $this->translator->trans('Neu veröffentlichte News und Artikel.', [], 'messages', $site->getDefaultLocale()),
            ),
            $articles->findPublished(limit: self::RSS_ITEM_LIMIT),
        );
    }

    #[Route('/videos.rss', name: 'video_feed', methods: ['GET'])]
    public function videoFeed(
        VideoRepository $videos,
        SiteSettingsProvider $siteSettings,
    ): Response {
        if (!$this->publicUrls->isConfigured()) {
            return $this->canonicalUrlUnavailable();
        }

        $site = $siteSettings->current();

        return $this->rss(
            $site->getSiteName().' Videos',
            $this->feedDescription(
                $site->getTagline(),
                $this->translator->trans('Neu veröffentlichte Videos.', [], 'messages', $site->getDefaultLocale()),
            ),
            $videos->findPublished(limit: self::RSS_ITEM_LIMIT),
        );
    }

    #[Route('/sitemap.xml', name: 'content_sitemap', methods: ['GET'])]
    public function sitemap(
        NewsArticleRepository $articles,
        VideoRepository $videos,
        StaticPageRepository $pages,
        ContentSeriesRepository $series,
        SeriesEpisodeRepository $episodes,
        ModuleAvailability $modules,
    ): Response {
        if (!$this->publicUrls->isConfigured()) {
            return $this->canonicalUrlUnavailable();
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $this->appendSitemapEntry(
            $xml,
            $this->publicUrls->generate('platform_home'),
            null,
        );

        $remaining = self::MAX_SITEMAP_URLS - 1;
        if ($modules->isEnabled('news')) {
            $offset = 0;
            do {
                $batch = $articles->findPublished(
                    limit: min(self::SITEMAP_BATCH_SIZE, $remaining),
                    offset: $offset,
                );
                foreach ($batch as $article) {
                    $publishedAt = $article->getPublishedAt();
                    if (null !== $publishedAt) {
                        $this->appendSitemapEntry(
                            $xml,
                            $this->publicUrls->generate('news_show', ['slug' => $article->getSlug()]),
                            $publishedAt,
                        );
                    }
                }
                $batchSize = count($batch);
                $remaining -= $batchSize;
                $offset += $batchSize;
            } while (self::SITEMAP_BATCH_SIZE === $batchSize && $remaining > 0);
        }

        if ($remaining > 0) {
            $offset = 0;
            do {
                $batch = $videos->findPublished(
                    limit: min(self::SITEMAP_BATCH_SIZE, $remaining),
                    offset: $offset,
                );
                foreach ($batch as $video) {
                    $publishedAt = $video->getPublishedAt();
                    if (null !== $publishedAt) {
                        $this->appendSitemapEntry(
                            $xml,
                            $this->publicUrls->generate('video_show', ['id' => $video->getId()]),
                            $publishedAt,
                        );
                    }
                }
                $batchSize = count($batch);
                $remaining -= $batchSize;
                $offset += $batchSize;
            } while (self::SITEMAP_BATCH_SIZE === $batchSize && $remaining > 0);
        }

        if ($remaining > 0 && $modules->isEnabled('pages')) {
            $offset = 0;
            do {
                $batch = $pages->findPublished(
                    limit: min(self::SITEMAP_BATCH_SIZE, $remaining),
                    offset: $offset,
                );
                foreach ($batch as $page) {
                    $publishedAt = $page->getPublishedAt();
                    if (null !== $publishedAt) {
                        $this->appendSitemapEntry(
                            $xml,
                            $this->publicUrls->generate('page_show', ['slug' => $page->getSlug()]),
                            $publishedAt,
                        );
                    }
                }
                $batchSize = count($batch);
                $remaining -= $batchSize;
                $offset += $batchSize;
            } while (self::SITEMAP_BATCH_SIZE === $batchSize && $remaining > 0);
        }

        if ($remaining > 0 && $modules->isEnabled('series')) {
            $offset = 0;
            do {
                $batch = $series->findPublished(
                    limit: min(self::SITEMAP_BATCH_SIZE, $remaining),
                    offset: $offset,
                );
                foreach ($batch as $seriesItem) {
                    $publishedAt = $seriesItem->getPublishedAt();
                    if (null !== $publishedAt) {
                        $this->appendSitemapEntry(
                            $xml,
                            $this->publicUrls->generate('series_show', ['slug' => $seriesItem->getSlug()]),
                            $publishedAt,
                        );
                    }
                }
                $batchSize = count($batch);
                $remaining -= $batchSize;
                $offset += $batchSize;
            } while (self::SITEMAP_BATCH_SIZE === $batchSize && $remaining > 0);
        }

        if ($remaining > 0 && $modules->isEnabled('series')) {
            $offset = 0;
            do {
                $batch = $episodes->findPublishedForSitemap(
                    limit: min(self::SITEMAP_BATCH_SIZE, $remaining),
                    offset: $offset,
                );
                foreach ($batch as $episode) {
                    $publishedAt = $episode->getVideo()->getPublishedAt();
                    if (null !== $publishedAt) {
                        $this->appendSitemapEntry(
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
            } while (self::SITEMAP_BATCH_SIZE === $batchSize && $remaining > 0);
        }

        $xml .= '</urlset>';

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    #[Route('/sitemap-index.xml', name: 'sitemap_index', methods: ['GET'])]
    public function sitemapIndex(): Response
    {
        if (!$this->publicUrls->isConfigured()) {
            return $this->canonicalUrlUnavailable();
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (['content_sitemap', 'video_sitemap'] as $route) {
            $location = $this->publicUrls->generate($route);
            $xml .= '<sitemap><loc>'.self::escapeXml($location).'</loc></sitemap>';
        }
        $xml .= '</sitemapindex>';

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    #[Route('/sitemap-video.xml', name: 'video_sitemap', methods: ['GET'])]
    public function videoSitemap(
        VideoRepository $videos,
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
    ): Response {
        if (!$this->publicUrls->isConfigured()) {
            return $this->canonicalUrlUnavailable();
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">';
        $offset = 0;
        $scanned = 0;
        $stop = false;

        do {
            $limit = min(self::SITEMAP_BATCH_SIZE, self::MAX_SITEMAP_URLS - $scanned);
            $batch = $videos->findPublished(limit: $limit, offset: $offset);
            foreach ($batch as $video) {
                $entry = $this->videoSitemapEntry($video, $embedResolver, $streamResolver);
                if (null === $entry) {
                    continue;
                }

                if (strlen($xml) + strlen($entry) + strlen('</urlset>') > self::MAX_SITEMAP_BYTES) {
                    $stop = true;
                    break;
                }

                $xml .= $entry;
            }

            $batchSize = count($batch);
            $offset += $batchSize;
            $scanned += $batchSize;
        } while (!$stop && $batchSize === $limit && $scanned < self::MAX_SITEMAP_URLS);

        $xml .= '</urlset>';

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    #[Route('/robots.txt', name: 'robots_txt', methods: ['GET'])]
    public function robots(SiteSettingsProvider $siteSettings): Response
    {
        $settings = $siteSettings->current();
        $mayIndex = $settings->isSearchIndexingEnabled() && !$settings->isMaintenanceModeEnabled();
        $directive = $mayIndex ? "Allow: /\n" : "Disallow: /\n";
        if ($mayIndex && !$this->publicUrls->isConfigured()) {
            return $this->canonicalUrlUnavailable();
        }
        $sitemap = $mayIndex ? 'Sitemap: '.$this->publicUrls->generate('sitemap_index')."\n" : '';

        return new Response(
            "User-agent: *\n".$directive.$sitemap,
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    /**
     * @return array{tag: 'player_loc'|'content_loc', url: string}|null
     */
    private function mediaLocationForVideo(
        Video $video,
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
    ): ?array {
        if (VideoSourceType::File === $video->getSourceType()) {
            $objectId = $video->getUploadedObjectId();
            $mimeType = $video->getUploadedMimeType();
            $size = $video->getUploadedSize();
            if (null === $objectId
                || 1 !== preg_match('/\\A[a-f0-9]{64}\\z/D', $objectId)
                || null === $mimeType
                || !in_array($mimeType, ['video/mp4', 'video/webm', 'video/ogg'], true)
                || null === $size
                || $size < 1
                || $size > 33554432
            ) {
                return null;
            }

            return [
                'tag' => 'content_loc',
                'url' => $this->publicUrls->generate('video_media', ['id' => $video->getId()]),
            ];
        }

        if (VideoSourceType::Embed === $video->getSourceType()) {
            $playerUrl = $embedResolver->resolve($video->getSourceUrl());

            return null === $playerUrl ? null : ['tag' => 'player_loc', 'url' => $playerUrl];
        }

        if (VideoSourceType::Stream === $video->getSourceType()) {
            $source = $streamResolver->resolve($video->getSourceUrl());

            return null === $source ? null : ['tag' => 'content_loc', 'url' => $source->url];
        }

        // Allgemeine Video-URLs werden nur verlinkt und nicht als Mediendatei ausgegeben.
        return null;
    }

    private function videoSitemapEntry(
        Video $video,
        ExternalVideoEmbedResolver $embedResolver,
        VideoStreamSourceResolver $streamResolver,
    ): ?string {
        $thumbnailObjectId = $video->getThumbnailObjectId();
        $location = $this->mediaLocationForVideo($video, $embedResolver, $streamResolver);
        if (null === $thumbnailObjectId
            || 1 !== preg_match('/\\A[a-f0-9]{64}\\z/D', $thumbnailObjectId)
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
        $publishedAt = $video->getPublishedAt();
        if (null !== $publishedAt) {
            $xml .= '<video:publication_date>'.$publishedAt->format(DATE_ATOM).'</video:publication_date>';
        }
        $xml .= '</video:video></url>';

        return $xml;
    }

    /**
     * @param list<NewsArticle|Video> $items
     */
    private function rss(
        string $title,
        string $description,
        array $items,
    ): Response {
        $homeUrl = $this->publicUrls->generate('platform_home');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<rss version="2.0"><channel>';
        $xml .= '<title>'.self::escapeXml($title).'</title>';
        $xml .= '<link>'.self::escapeXml($homeUrl).'</link>';
        $xml .= '<description>'.self::escapeXml($description).'</description>';

        foreach ($items as $item) {
            $publishedAt = $item->getPublishedAt();
            if (null === $publishedAt) {
                continue;
            }

            if ($item instanceof NewsArticle) {
                $url = $this->publicUrls->generate('news_show', ['slug' => $item->getSlug()]);
                $summary = $item->getSummary() ?? $item->getBody();
            } else {
                $url = $this->publicUrls->generate('video_show', ['id' => $item->getId()]);
                $summary = $item->getDescription();
            }

            $xml .= '<item>';
            $xml .= '<title>'.self::escapeXml($item->getTitle()).'</title>';
            $xml .= '<link>'.self::escapeXml($url).'</link>';
            $xml .= '<guid isPermaLink="true">'.self::escapeXml($url).'</guid>';
            $xml .= '<pubDate>'.$publishedAt->format(DATE_RSS).'</pubDate>';
            $xml .= '<description>'.self::escapeXml(mb_substr($summary, 0, 2000)).'</description>';
            $xml .= '</item>';
        }

        $xml .= '</channel></rss>';

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/rss+xml; charset=UTF-8']);
    }

    private function feedDescription(string $tagline, string $contentDescription): string
    {
        $tagline = trim($tagline);

        return '' === $tagline ? $contentDescription : $tagline.' '.$contentDescription;
    }

    private function appendSitemapEntry(string &$xml, string $url, ?\DateTimeImmutable $lastModified): void
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

    private function canonicalUrlUnavailable(): Response
    {
        return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
            'Cache-Control' => 'private, no-store',
            'Retry-After' => '300',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
