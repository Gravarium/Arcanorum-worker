<?php

declare(strict_types=1);

namespace App\Module\Feed\Controller;

use App\Entity\NewsArticle;
use App\Entity\Video;
use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use App\Module\Feed\Service\SitemapDocumentService;
use App\Module\Settings\Service\ModuleAvailability;
use App\Repository\NewsArticleRepository;
use App\Repository\VideoRepository;
use App\Service\SiteSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ContentFeedController extends AbstractController
{
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
            $articles->findPublished(limit: self::RSS_ITEM_LIMIT, excludeMembersOnly: true),
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
    public function sitemap(SitemapDocumentService $sitemaps): Response
    {
        $xml = $sitemaps->contentSitemap();
        if (null === $xml) {
            return $this->canonicalUrlUnavailable();
        }

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    #[Route('/sitemap-index.xml', name: 'sitemap_index', methods: ['GET'])]
    public function sitemapIndex(SitemapDocumentService $sitemaps): Response
    {
        $xml = $sitemaps->sitemapIndex();
        if (null === $xml) {
            return $this->canonicalUrlUnavailable();
        }

        return new Response($xml, Response::HTTP_OK, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    #[Route('/sitemap-video.xml', name: 'video_sitemap', methods: ['GET'])]
    public function videoSitemap(SitemapDocumentService $sitemaps): Response
    {
        $xml = $sitemaps->videoSitemap();
        if (null === $xml) {
            return $this->canonicalUrlUnavailable();
        }

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

    private static function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

    private function canonicalUrlUnavailable(): Response
    {
        return new Response('', Response::HTTP_SERVICE_UNAVAILABLE, [
            'Cache-Control' => 'private, no-store',
            'Retry-After' => '300',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
