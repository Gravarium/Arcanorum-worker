<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentSeries;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\SeriesEpisode;
use App\Entity\StaticPage;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Feed\Service\CanonicalPublicUrlGenerator;
use App\Module\Video\Service\VideoStreamSourceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ContentFeedTest extends WebTestCase
{
    public function testFeedsAndSitemapIncludePublishedContentOnlyAndEscapeXml(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $originalTagline = $connection->fetchOne('SELECT tagline FROM site_settings WHERE id = 1');
        self::assertIsString($originalTagline);
        $originalLocale = $connection->fetchOne('SELECT default_locale FROM site_settings WHERE id = 1');
        self::assertIsString($originalLocale);
        $connection->executeStatement('UPDATE site_settings SET tagline = :tagline WHERE id = 1', [
            'tagline' => 'Videos & News für alle',
        ]);

        $suffix = bin2hex(random_bytes(8));
        $newsSlug = 'feed-news-'.$suffix;
        $draftNewsSlug = 'feed-draft-news-'.$suffix;
        $pageSlug = 'feed-page-'.$suffix;
        $draftPageSlug = 'feed-draft-page-'.$suffix;
        $newsTitle = 'News & <script>'.$suffix;
        $draftNewsTitle = 'Draft News '.$suffix;
        $videoTitle = 'Video & Update '.$suffix;
        $draftVideoTitle = 'Draft Video '.$suffix;
        $pageTitle = 'Page & Info '.$suffix;
        $seriesSlug = 'feed-series-'.$suffix;
        $draftSeriesSlug = 'feed-draft-series-'.$suffix;
        $seriesTitle = 'Series feed '.$suffix;
        $draftSeriesTitle = 'Draft series feed '.$suffix;
        $draftPageTitle = 'Draft Page '.$suffix;

        $news = new NewsArticle($newsTitle, $newsSlug, null, 'Feed summary', 'Feed body', PublishingStatus::Published, false, false);
        $draftNews = new NewsArticle($draftNewsTitle, $draftNewsSlug, null, null, 'Draft body', PublishingStatus::Draft, false, false);
        $video = new Video(
            $videoTitle,
            'Video feed description',
            null,
            VideoSourceType::Url,
            'https://video.example.test/feed.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $draftVideo = new Video(
            $draftVideoTitle,
            'Draft video description',
            null,
            VideoSourceType::Url,
            'https://video.example.test/draft.mp4',
            PublishingStatus::Draft,
            false,
            false,
        );
        $page = new StaticPage($pageTitle, $pageSlug, null, 'Page body', PublishingStatus::Published);
        $draftPage = new StaticPage($draftPageTitle, $draftPageSlug, null, 'Draft page body', PublishingStatus::Draft);
        $series = new ContentSeries($seriesTitle, $seriesSlug, 'Series feed description', PublishingStatus::Published);
        $draftSeries = new ContentSeries($draftSeriesTitle, $draftSeriesSlug, 'Draft series description', PublishingStatus::Draft);
        $episode = new SeriesEpisode($series, $video, 1, 1);
        $draftVideoEpisode = new SeriesEpisode($series, $draftVideo, 1, 2);

        try {
            foreach ([$news, $draftNews, $video, $draftVideo, $page, $draftPage, $series, $draftSeries, $episode, $draftVideoEpisode] as $content) {
                $entityManager->persist($content);
            }
            $entityManager->flush();

            $client->request('GET', '/news.rss', server: [
                'HTTPS' => 'on',
                'HTTP_HOST' => 'attacker.example.test',
                'HTTP_X_FORWARDED_HOST' => 'forwarded-attacker.example.test',
            ]);
            self::assertResponseIsSuccessful();
            self::assertStringStartsWith('application/rss+xml', (string) $client->getResponse()->headers->get('Content-Type'));
            self::assertStringContainsString("default-src 'self'", (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertStringContainsString('Videos &amp; News für alle Neu veröffentlichte News und Artikel.', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('News &amp; &lt;script&gt;'.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringContainsString('https://arcanorum.example.test/news/'.$newsSlug, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('attacker.example.test', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($draftNewsTitle, (string) $client->getResponse()->getContent());
            $rssDocument = new \DOMDocument();
            self::assertTrue($rssDocument->loadXML((string) $client->getResponse()->getContent(), LIBXML_NONET));

            $client->request('GET', '/videos.rss');
            self::assertResponseIsSuccessful();
            self::assertStringStartsWith('application/rss+xml', (string) $client->getResponse()->headers->get('Content-Type'));
            self::assertStringContainsString("default-src 'self'", (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertStringContainsString('Videos &amp; News für alle Neu veröffentlichte Videos.', (string) $client->getResponse()->getContent());
            self::assertStringContainsString(htmlspecialchars($videoTitle, ENT_XML1 | ENT_QUOTES, 'UTF-8'), (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($draftVideoTitle, (string) $client->getResponse()->getContent());

            $connection->executeStatement(
                'UPDATE site_settings SET default_locale = :locale, tagline = :tagline WHERE id = 1',
                ['locale' => 'en', 'tagline' => 'News & videos for everyone'],
            );

            $client->request('GET', '/news.rss');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString(
                'News &amp; videos for everyone Recently published news and articles.',
                (string) $client->getResponse()->getContent(),
            );

            $client->request('GET', '/videos.rss');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString(
                'News &amp; videos for everyone Recently published videos.',
                (string) $client->getResponse()->getContent(),
            );

            $client->request('GET', '/sitemap.xml', server: [
                'HTTPS' => 'on',
                'HTTP_HOST' => 'attacker.example.test',
                'HTTP_X_FORWARDED_HOST' => 'forwarded-attacker.example.test',
            ]);
            self::assertResponseIsSuccessful();
            self::assertStringStartsWith('application/xml', (string) $client->getResponse()->headers->get('Content-Type'));
            self::assertStringContainsString("default-src 'self'", (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertStringContainsString('https://arcanorum.example.test/', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('attacker.example.test', (string) $client->getResponse()->getContent());
            $sitemapDocument = new \DOMDocument();
            self::assertTrue($sitemapDocument->loadXML((string) $client->getResponse()->getContent(), LIBXML_NONET));
            self::assertStringContainsString('/news/'.$newsSlug, (string) $client->getResponse()->getContent());
            self::assertStringContainsString('/videos/'.$video->getId(), (string) $client->getResponse()->getContent());
            self::assertStringContainsString('/pages/'.$pageSlug, (string) $client->getResponse()->getContent());
            self::assertStringContainsString('/series/'.$seriesSlug, (string) $client->getResponse()->getContent());
            self::assertStringContainsString('/series/'.$seriesSlug.'/season/1/episode/1', (string) $client->getResponse()->getContent());

            self::assertStringNotContainsString($draftNewsSlug, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($draftVideo->getId(), (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($draftPageSlug, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString($draftSeriesSlug, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('/season/1/episode/2', (string) $client->getResponse()->getContent());
        } finally {
            $connection->executeStatement('DELETE FROM news_articles WHERE slug IN (:published, :draft)', [
                'published' => $newsSlug,
                'draft' => $draftNewsSlug,
            ]);
            $connection->executeStatement('DELETE FROM videos WHERE id IN (:published, :draft)', [
                'published' => $video->getId(),
                'draft' => $draftVideo->getId(),
            ]);
            $connection->executeStatement('DELETE FROM content_series WHERE slug IN (:published, :draft)', [
                'published' => $seriesSlug,
                'draft' => $draftSeriesSlug,
            ]);
            $connection->executeStatement('DELETE FROM static_pages WHERE slug IN (:published, :draft)', [
                'published' => $pageSlug,
                'draft' => $draftPageSlug,
            ]);
            $connection->executeStatement(
                'UPDATE site_settings SET tagline = :tagline, default_locale = :locale WHERE id = 1',
                ['tagline' => $originalTagline, 'locale' => $originalLocale],
            );
        }
    }

    public function testVideoSitemapAndRobotsIndexIncludeOnlyPublicPlayableVideos(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(VideoStreamSourceResolver::class, new VideoStreamSourceResolver(['media.example.org']));
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(8));
        $embedTitle = 'Embed & Video <title> '.$suffix;
        $fileTitle = 'Uploaded video '.$suffix;
        $draftTitle = 'Draft embed '.$suffix;
        $externalTitle = 'External link '.$suffix;
        $streamTitle = 'Direct stream '.$suffix;
        $unapprovedStreamTitle = 'Unapproved stream '.$suffix;
        $embed = new Video(
            $embedTitle,
            'Beschreibung & Details',
            null,
            VideoSourceType::Embed,
            'https://youtu.be/BaW_jenozKc',
            PublishingStatus::Published,
            false,
            false,
        );
        $embed->attachThumbnail(str_repeat('a', 48).$suffix, 'image/jpeg', 128);

        $file = new Video(
            $fileTitle,
            'Lokale Videodatei',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Published,
            false,
            false,
        );
        $file->attachUploadedMedia(str_repeat('b', 48).$suffix, 'video/mp4', 1024);
        $file->attachThumbnail(str_repeat('c', 48).$suffix, 'image/webp', 256);

        $draft = new Video(
            $draftTitle,
            'Nicht öffentlich',
            null,
            VideoSourceType::Embed,
            'https://youtu.be/BaW_jenozKc',
            PublishingStatus::Draft,
            false,
            false,
        );
        $draft->attachThumbnail(str_repeat('d', 48).$suffix, 'image/jpeg', 128);

        $external = new Video(
            $externalTitle,
            'Nur verlinkt',
            null,
            VideoSourceType::Url,
            'https://media.example.test/clip.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $external->attachThumbnail(str_repeat('e', 48).$suffix, 'image/jpeg', 128);

        $streamUrl = 'https://media.example.org/video-sitemap/'.$suffix.'.mp4';
        $stream = new Video(
            $streamTitle,
            'Direct stream video source.',
            null,
            VideoSourceType::Stream,
            $streamUrl,
            PublishingStatus::Published,
            false,
            false,
        );
        $stream->attachThumbnail(str_repeat('f', 48).$suffix, 'image/jpeg', 128);

        $unapprovedStream = new Video(
            $unapprovedStreamTitle,
            'Stream host not approved for public playback.',
            null,
            VideoSourceType::Stream,
            'https://unapproved.example.org/video.mp4',
            PublishingStatus::Published,
            false,
            false,
        );
        $unapprovedStream->attachThumbnail(str_repeat('0', 48).$suffix, 'image/jpeg', 128);

        try {
            foreach ([$embed, $file, $draft, $external, $stream, $unapprovedStream] as $video) {
                $entityManager->persist($video);
            }
            $entityManager->flush();

            $client->request('GET', '/sitemap-video.xml', server: ['HTTP_HOST' => 'attacker.example.test']);
            self::assertResponseIsSuccessful();
            self::assertStringStartsWith('application/xml', (string) $client->getResponse()->headers->get('Content-Type'));
            $xml = (string) $client->getResponse()->getContent();
            $document = new \DOMDocument();
            self::assertTrue($document->loadXML($xml, LIBXML_NONET));
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('sitemap', 'http://www.sitemaps.org/schemas/sitemap/0.9');
            $xpath->registerNamespace('video', 'http://www.google.com/schemas/sitemap-video/1.1');
            $urlNodes = $xpath->query('/sitemap:urlset/sitemap:url');
            self::assertNotFalse($urlNodes);
            self::assertSame(3, $urlNodes->length);
            self::assertStringContainsString(htmlspecialchars($embedTitle, ENT_XML1 | ENT_QUOTES, 'UTF-8'), $xml);
            self::assertStringContainsString('https://arcanorum.example.test/videos/'.$embed->getId(), $xml);
            self::assertStringNotContainsString('attacker.example.test', $xml);
            self::assertStringContainsString('https://www.youtube-nocookie.com/embed/BaW_jenozKc', $xml);
            self::assertStringContainsString('/videos/'.$embed->getId().'/thumbnail', $xml);
            self::assertStringContainsString('/videos/'.$file->getId().'/media', $xml);
            self::assertStringContainsString($streamUrl, $xml);
            self::assertStringNotContainsString($draftTitle, $xml);
            self::assertStringNotContainsString($externalTitle, $xml);
            self::assertStringNotContainsString($unapprovedStreamTitle, $xml);

            $client->request('GET', '/sitemap-index.xml', server: ['HTTP_HOST' => 'attacker.example.test']);
            self::assertResponseIsSuccessful();
            $index = (string) $client->getResponse()->getContent();
            $indexDocument = new \DOMDocument();
            self::assertTrue($indexDocument->loadXML($index, LIBXML_NONET));
            self::assertStringContainsString('/sitemap.xml', $index);
            self::assertStringContainsString('/sitemap-video.xml', $index);
            self::assertStringContainsString('https://arcanorum.example.test/sitemap.xml', $index);
            self::assertStringNotContainsString('attacker.example.test', $index);

            $client->request('GET', '/robots.txt', server: ['HTTP_HOST' => 'attacker.example.test']);
            self::assertResponseIsSuccessful();
            self::assertStringStartsWith('text/plain', (string) $client->getResponse()->headers->get('Content-Type'));
            self::assertStringContainsString('/sitemap-index.xml', (string) $client->getResponse()->getContent());
            self::assertStringContainsString('Sitemap: https://arcanorum.example.test/sitemap-index.xml', (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('attacker.example.test', (string) $client->getResponse()->getContent());
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM videos WHERE id IN (:embed, :file, :draft, :external, :stream, :unapproved_stream)',
                [
                    'embed' => $embed->getId(),
                    'file' => $file->getId(),
                    'draft' => $draft->getId(),
                    'external' => $external->getId(),
                    'stream' => $stream->getId(),
                    'unapproved_stream' => $unapprovedStream->getId(),
                ],
            );
        }
    }

    public function testCanonicalFeedsFailClosedWhenNoTrustedOriginIsConfigured(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $container = $client->getContainer();
        $router = $container->get(UrlGeneratorInterface::class);
        self::assertInstanceOf(UrlGeneratorInterface::class, $router);
        $container->set(CanonicalPublicUrlGenerator::class, new CanonicalPublicUrlGenerator($router, ''));

        foreach (['/news.rss', '/videos.rss', '/sitemap.xml', '/sitemap-index.xml', '/sitemap-video.xml', '/robots.txt'] as $path) {
            $client->request('GET', $path, server: ['HTTP_HOST' => 'attacker.example.test']);
            self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE, $path);
            self::assertSame('no-store, private', $client->getResponse()->headers->get('Cache-Control'), $path);
            self::assertSame('noindex, nofollow', $client->getResponse()->headers->get('X-Robots-Tag'), $path);
        }
    }
}
