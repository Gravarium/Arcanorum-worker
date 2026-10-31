<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\SiteSettings;
use App\Entity\StaticPage;
use App\Entity\Video;
use App\Entity\VideoPreloadMode;
use App\Entity\VideoSourceType;
use App\Entity\VideoSubtitleTrack;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Platform\Hosting\PrivateObjectStorageInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicContentTest extends WebTestCase
{
    public function testPublicPagesShowPublishedContentAndHideDrafts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(VideoStreamSourceResolver::class, new VideoStreamSourceResolver(['media.example.org']));
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $storedLocale = $connection->fetchOne('SELECT default_locale FROM site_settings WHERE id = :id', ['id' => SiteSettings::SINGLETON_ID]);
        $originalLocale = is_string($storedLocale) ? $storedLocale : null;

        $suffix = bin2hex(random_bytes(8));
        $publishedSlug = 'public-news-'.$suffix;
        $draftSlug = 'draft-news-'.$suffix;
        $memberOnlySlug = 'members-only-news-'.$suffix;
        $publishedArticleTitle = 'Public News '.$suffix;
        $draftArticleTitle = 'Draft News '.$suffix;
        $memberOnlyArticleTitle = 'Members Only News '.$suffix;
        $publishedPageTitle = 'Public Page '.$suffix;
        $draftPageTitle = 'Draft Page '.$suffix;
        $publishedPageSlug = 'public-page-'.$suffix;
        $draftPageSlug = 'draft-page-'.$suffix;
        $publishedVideoTitle = 'Public Video '.$suffix;
        $draftVideoTitle = 'Draft Video '.$suffix;
        $streamVideoTitle = 'Stream Video '.$suffix;
        $liveVideoTitle = 'Live Video '.$suffix;
        $unapprovedStreamTitle = 'Unapproved Stream '.$suffix;
        $newsCategoryName = 'News '.$suffix;
        $videoCategoryName = 'Video '.$suffix;
        $publishedVideo = new Video(
            $publishedVideoTitle,
            'Public video description <img src=x onerror="alert(1)"> & more',
            $videoCategoryName,
            VideoSourceType::Url,
            'https://video.example.test/sample.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $draftVideo = new Video(
            $draftVideoTitle,
            'Private draft description',
            $videoCategoryName,
            VideoSourceType::Url,
            'https://video.example.test/draft.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $embeddedVideoTitle = 'Embedded Video '.$suffix;
        $embeddedVideo = new Video(
            $embeddedVideoTitle,
            'A video embedded from an allowlisted provider.',
            $videoCategoryName,
            VideoSourceType::Embed,
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLsafe',
            PublishingStatus::Published,
            false,
            true,
        );
        $embeddedVideo->setEmbedAllowedOrigins(['https://publisher.example.org']);
        $invalidEmbedVideoTitle = 'Invalid Embedded Video '.$suffix;
        $invalidEmbedVideo = new Video(
            $invalidEmbedVideoTitle,
            'A legacy source that must fail closed.',
            $videoCategoryName,
            VideoSourceType::Embed,
            'https://www.youtube.com.evil.example/embed/dQw4w9WgXcQ',
            PublishingStatus::Published,
            false,
            true,
        );
        $streamUrl = 'https://media.example.org/videos/stream-'.$suffix.'.mp4?token=public-test';
        $streamVideo = new Video(
            $streamVideoTitle,
            'A direct stream with an allowlisted host.',
            $videoCategoryName,
            VideoSourceType::Stream,
            $streamUrl,
            PublishingStatus::Published,
            false,
            true,
        );
        $subtitleContents = "WEBVTT\n\n00:00.000 --> 00:02.000\nMobile subtitles\n";
        $subtitleStorageId = str_repeat('c', 64);
        $streamSubtitle = new VideoSubtitleTrack(
            $streamVideo,
            'en',
            'English',
            $subtitleStorageId,
            strlen($subtitleContents),
            hash('sha256', $subtitleContents),
        );
        $liveUrl = 'https://media.example.org/live/channel-'.$suffix.'.m3u8?token=public-test';
        $liveVideo = new Video(
            $liveVideoTitle,
            'An HLS live playlist with an allowlisted host.',
            $videoCategoryName,
            VideoSourceType::Stream,
            $liveUrl,
            PublishingStatus::Published,
            false,
            true,
        );
        $unapprovedStreamVideo = new Video(
            $unapprovedStreamTitle,
            'A stream from a host not approved for public playback.',
            $videoCategoryName,
            VideoSourceType::Stream,
            'https://unapproved.example.org/videos/private.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $thumbnailPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/WioAAAAASUVORK5CYII=', true);
        self::assertIsString($thumbnailPng);
        $publishedThumbnailId = str_repeat('a', 64);
        $draftThumbnailId = str_repeat('b', 64);
        $publishedVideo->attachThumbnail($publishedThumbnailId, 'image/png', strlen($thumbnailPng));
        $draftVideo->attachThumbnail($draftThumbnailId, 'image/png', strlen($thumbnailPng));
        $thumbnailStorage = $this->createMock(PrivateObjectStorageInterface::class);
        $thumbnailStorage
            ->expects($this->exactly(2))
            ->method('read')
            ->willReturnMap([
                [$publishedThumbnailId, $thumbnailPng],
                [$subtitleStorageId, $subtitleContents],
            ]);
        $client->getContainer()->set(PrivateObjectStorageInterface::class, $thumbnailStorage);

        $publishedArticle = new NewsArticle(
            $publishedArticleTitle,
            $publishedSlug,
            $newsCategoryName,
            'Public article summary',
            'Public article body '.$suffix,
            PublishingStatus::Published,
            false,
            true,
        );
        $publishedArticle->attachThumbnail(str_repeat('c', 64), 'image/png', strlen($thumbnailPng));
        $memberOnlyArticle = new NewsArticle(
            $memberOnlyArticleTitle,
            $memberOnlySlug,
            $newsCategoryName,
            'Member-only summary must not appear in public social metadata.',
            'Member-only article body '.$suffix,
            PublishingStatus::Published,
            false,
            true,
            'Redaktion',
            true,
        );
        $memberOnlyArticle->attachThumbnail(str_repeat('d', 64), 'image/png', strlen($thumbnailPng));
        $draftArticle = new NewsArticle(
            $draftArticleTitle,
            $draftSlug,
            $newsCategoryName,
            'Draft article summary',
            'Draft article body '.$suffix,
            PublishingStatus::Draft,
            false,
            true,
        );
        $publishedPage = new StaticPage(
            $publishedPageTitle,
            $publishedPageSlug,
            'Public page summary',
            'Public page body '.$suffix.' <script>blocked</script>',
            PublishingStatus::Published,
            'information, community',
            'Page specific SEO description',
            'Page Editor',
            true,
        );
        $newsCategory = new ContentCategory($newsCategoryName, ContentCategoryType::News);
        $videoCategory = new ContentCategory($videoCategoryName, ContentCategoryType::Video);
        $draftPage = new StaticPage(
            $draftPageTitle,
            $draftPageSlug,
            'Draft page summary',
            'Draft page body '.$suffix,
            PublishingStatus::Draft,
        );

        try {
            $entityManager->persist($newsCategory);
            $entityManager->persist($videoCategory);
            $entityManager->persist($publishedArticle);
            $entityManager->persist($draftArticle);
            $entityManager->persist($memberOnlyArticle);
            $entityManager->persist($publishedPage);
            $entityManager->persist($draftPage);
            $entityManager->persist($publishedVideo);
            $entityManager->persist($draftVideo);
            $entityManager->persist($embeddedVideo);
            $entityManager->persist($invalidEmbedVideo);
            $entityManager->persist($streamVideo);
            $entityManager->persist($streamSubtitle);
            $entityManager->persist($liveVideo);
            $entityManager->persist($unapprovedStreamVideo);
            $entityManager->flush();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', $publishedArticleTitle);
            self::assertSelectorTextContains('main', $publishedVideoTitle);
            self::assertStringNotContainsString($draftArticleTitle, $client->getCrawler()->filter('main')->text());
            self::assertStringNotContainsString($draftVideoTitle, $client->getCrawler()->filter('main')->text());

            $client->request('GET', '/news');
            self::assertResponseIsSuccessful();
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertSelectorTextContains('main', $publishedArticleTitle);
            self::assertStringNotContainsString($draftArticleTitle, $client->getCrawler()->filter('main')->text());

            $client->request('GET', '/news?category='.urlencode($newsCategoryName));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', $publishedArticleTitle);
            self::assertStringNotContainsString($draftArticleTitle, $client->getCrawler()->filter('main')->text());
            $client->request('GET', '/news?category='.urlencode($videoCategoryName));
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($publishedArticleTitle, $client->getCrawler()->filter('main')->text());

            $client->request('GET', '/news/'.$publishedSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Public article body '.$suffix);
            self::assertSame('article', $client->getCrawler()->filter('meta[property="og:type"]')->attr('content'));
            self::assertSame($publishedArticleTitle, $client->getCrawler()->filter('meta[property="og:title"]')->attr('content'));
            self::assertSame('Public article summary', $client->getCrawler()->filter('meta[property="og:description"]')->attr('content'));
            self::assertSame(
                'https://arcanorum.example.test/news/'.$publishedSlug,
                $client->getCrawler()->filter('link[rel="canonical"]')->attr('href'),
            );
            self::assertSame(
                'https://arcanorum.example.test/news/'.$publishedArticle->getId().'/thumbnail',
                $client->getCrawler()->filter('meta[property="og:image"]')->attr('content'),
            );
            self::assertSame('image/png', $client->getCrawler()->filter('meta[property="og:image:type"]')->attr('content'));
            $client->request('GET', '/news/'.$memberOnlySlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('meta[property^="og:"]');
            self::assertSelectorNotExists('meta[name^="twitter:"]');
            $client->request('GET', '/news/'.$draftSlug);
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/pages');
            self::assertResponseIsSuccessful();
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertSelectorTextContains('main', $publishedPageTitle);
            self::assertStringNotContainsString($draftPageTitle, $client->getCrawler()->filter('main')->text());

            $client->request('GET', '/pages/'.$publishedPageSlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'Public page body '.$suffix);
            self::assertSelectorTextContains('main', 'Von Page Editor');
            self::assertSelectorExists('meta[name="keywords"][content="information, community"]');
            self::assertSelectorExists('meta[name="description"][content="Page specific SEO description"]');
            self::assertSelectorExists('nav.public-navigation a[href="/pages/'.$publishedPageSlug.'"]');
            self::assertStringNotContainsString('blocked', (string) $client->getResponse()->getContent());
            self::assertSelectorNotExists('main script');
            $client->request('GET', '/pages/'.$draftPageSlug);
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/videos');
            self::assertResponseIsSuccessful();
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertSelectorTextContains('main', $publishedVideoTitle);
            self::assertStringNotContainsString($draftVideoTitle, $client->getCrawler()->filter('main')->text());

            $client->request('GET', '/videos?category='.urlencode($videoCategoryName));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', $publishedVideoTitle);
            self::assertStringNotContainsString($draftVideoTitle, $client->getCrawler()->filter('main')->text());
            $client->request('GET', '/videos?category='.urlencode($newsCategoryName));
            self::assertResponseIsSuccessful();
            self::assertStringNotContainsString($publishedVideoTitle, $client->getCrawler()->filter('main')->text());

            $client->request('GET', '/videos/'.$publishedVideo->getId(), server: ['HTTP_HOST' => 'attacker.example.test']);
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('img.video-thumbnail');
            self::assertSelectorTextContains('main', $publishedVideoTitle);
            self::assertSame('video.other', $client->getCrawler()->filter('meta[property="og:type"]')->attr('content'));
            self::assertSame(
                'https://arcanorum.example.test/videos/'.$publishedVideo->getId(),
                $client->getCrawler()->filter('meta[property="og:url"]')->attr('content'),
            );
            $socialPreviewSourceId = $publishedVideo->getThumbnailObjectId();
            self::assertIsString($socialPreviewSourceId);
            self::assertSame(
                'https://arcanorum.example.test/videos/'.$publishedVideo->getId().'/social-preview/'.\App\Service\VideoSocialPreviewGenerator::versionForSourceObjectId($socialPreviewSourceId),
                $client->getCrawler()->filter('meta[property="og:image"]')->attr('content'),
            );
            self::assertSame('image/jpeg', $client->getCrawler()->filter('meta[property="og:image:type"]')->attr('content'));
            self::assertSame('1200', $client->getCrawler()->filter('meta[property="og:image:width"]')->attr('content'));
            self::assertSame('630', $client->getCrawler()->filter('meta[property="og:image:height"]')->attr('content'));
            self::assertSame(
                'Public video description <img src=x onerror="alert(1)"> & more',
                $client->getCrawler()->filter('meta[property="og:description"]')->attr('content'),
            );
            self::assertSelectorNotExists('head img');
            self::assertSelectorExists('meta[name="twitter:card"][content="summary_large_image"]');
            self::assertSame(
                'https://video.example.test/sample.mp4',
                $client->getCrawler()->filter('[data-testid="video-source"]')->attr('href'),
            );
            $client->request('GET', '/videos/'.$embeddedVideo->getId());
            self::assertResponseIsSuccessful();
            self::assertSame(
                'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
                $client->getCrawler()->filter('[data-testid="video-embed-provider"]')->attr('data-embed-url'),
            );
            self::assertSelectorExists('[data-testid="video-embed-provider"] button[data-provider-embed-load]');
            self::assertSelectorNotExists('iframe[data-testid="video-embed-provider"]');
            self::assertStringContainsString('video/provider-embed-consent.js', (string) $client->getResponse()->getContent());
            self::assertSelectorNotExists('iframe[srcdoc]');
            self::assertSelectorNotExists('track[kind="subtitles"]');
            $embedPolicy = $client->getResponse()->headers->get('Content-Security-Policy-Report-Only');
            self::assertStringContainsString(
                "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com https://geo.dailymotion.com",
                $embedPolicy,
            );
            self::assertStringNotContainsString('*', $embedPolicy);
            self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));

            $client->request('GET', '/videos/'.$streamVideo->getId());
            self::assertResponseIsSuccessful();
            self::assertSame($streamUrl, $client->getCrawler()->filter('video[data-testid="video-stream"] source')->attr('src'));
            self::assertSame('video/mp4', $client->getCrawler()->filter('video[data-testid="video-stream"] source')->attr('type'));
            self::assertSelectorExists('video[data-testid="video-stream"][playsinline]');
            self::assertContains(
                $client->getCrawler()->filter('video[data-testid="video-stream"]')->attr('preload'),
                array_map(static fn (VideoPreloadMode $mode): string => $mode->value, VideoPreloadMode::cases()),
            );
            $subtitleTrack = $client->getCrawler()->filter('video[data-testid="video-stream"] track[kind="subtitles"]');
            self::assertCount(1, $subtitleTrack);
            self::assertSame('en', $subtitleTrack->attr('srclang'));
            self::assertSame('English', $subtitleTrack->attr('label'));
            self::assertSelectorExists('video[data-testid="video-stream"] track[default]');
            self::assertSame($streamUrl, $client->getCrawler()->filter('a[data-testid="video-stream-fallback"]')->attr('href'));
            self::assertStringContainsString("media-src 'self' https://media.example.org", $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));

            $subtitlePath = $subtitleTrack->attr('src');
            self::assertIsString($subtitlePath);
            $client->request('GET', $subtitlePath);
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'text/vtt; charset=UTF-8');
            self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
            self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
            self::assertSame($subtitleContents, $client->getResponse()->getContent());
            $client->request('GET', '/videos/'.$draftVideo->getId().'/subtitles/'.$streamSubtitle->getId());
            self::assertResponseStatusCodeSame(404);
            self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
            $client->request('GET', '/videos/'.$embeddedVideo->getId().'/subtitles/'.$streamSubtitle->getId());
            self::assertResponseStatusCodeSame(404);

            $siteSettings = $entityManager->getRepository(SiteSettings::class)->find(SiteSettings::SINGLETON_ID);
            $siteSettings = $siteSettings instanceof SiteSettings ? $siteSettings : new SiteSettings();
            self::assertSame([], $siteSettings->updateFromInput([
                'site_name' => $siteSettings->getSiteName(),
                'tagline' => $siteSettings->getTagline(),
                'locale' => 'ar',
                'timezone' => $siteSettings->getTimezone(),
            ]));
            $entityManager->persist($siteSettings);
            $entityManager->flush();
            $entityManager->clear();

            $client->request('GET', '/embed/video/'.$embeddedVideo->getId());
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('<html lang="ar" dir="rtl">', $client->getResponse()->getContent());

            $siteSettings = $entityManager->getRepository(SiteSettings::class)->find(SiteSettings::SINGLETON_ID);
            $siteSettings = $siteSettings instanceof SiteSettings ? $siteSettings : new SiteSettings();
            self::assertSame([], $siteSettings->updateFromInput([
                'site_name' => $siteSettings->getSiteName(),
                'tagline' => $siteSettings->getTagline(),
                'locale' => 'en',
                'timezone' => $siteSettings->getTimezone(),
            ]));
            $entityManager->persist($siteSettings);
            $entityManager->flush();
            $entityManager->clear();

            $client->request('GET', '/videos/'.$liveVideo->getId());
            self::assertResponseIsSuccessful();
            $hlsPlayer = $client->getCrawler()->filter('video[data-testid="video-stream"][data-hls-url]');
            self::assertSame($liveUrl, $hlsPlayer->attr('data-hls-url'));
            self::assertSame('This live stream is unavailable.', $hlsPlayer->attr('data-hls-message-invalid-url'));
            self::assertSame('This live stream is currently unavailable. Please try again later.', $hlsPlayer->attr('data-hls-message-error'));
            self::assertSame('Live stream ready. Press play.', $hlsPlayer->attr('data-hls-message-ready'));
            self::assertSame('This browser does not support live playback. Use the link to the stream.', $hlsPlayer->attr('data-hls-message-unsupported'));
            self::assertSelectorNotExists('video[data-testid="video-stream"] source');
            self::assertSelectorExists('p[data-hls-status][role="status"]');
            self::assertSame('no-referrer', $client->getCrawler()->filter('video[data-testid="video-stream"]')->attr('referrerpolicy'));
            self::assertStringContainsString('hls.js-1.7.3/hls.min.js', $client->getResponse()->getContent());
            self::assertStringContainsString('video/hls-player.js', $client->getResponse()->getContent());
            $livePolicy = $client->getResponse()->headers->get('Content-Security-Policy-Report-Only');
            self::assertStringContainsString("connect-src 'self' https://media.example.org", $livePolicy);
            self::assertStringContainsString("media-src 'self' https://media.example.org blob:", $livePolicy);
            self::assertStringContainsString("worker-src 'self' blob:", $livePolicy);
            self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));
            $this->restoreSiteLocale($connection, $originalLocale);
            $entityManager->clear();

            $client->request('GET', '/videos/'.$unapprovedStreamVideo->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('video[data-testid="video-stream"]');
            self::assertSelectorNotExists('a[data-testid="video-stream-fallback"]');
            self::assertSelectorTextContains('.video-stage', 'nicht verfügbar oder für diese Website nicht freigegeben');
            self::assertStringNotContainsString('unapproved.example.org', $client->getResponse()->getContent());

            $client->request('GET', '/videos/'.$invalidEmbedVideo->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('iframe[data-testid="video-embed"]');
            self::assertSelectorNotExists('[data-testid="video-embed-provider"]');
            self::assertSelectorNotExists('a[data-testid="video-source"]');
            self::assertSelectorTextContains('.video-stage', 'Diese Einbettung ist derzeit nicht verfügbar.');
            self::assertStringNotContainsString('youtube.com.evil.example', $client->getResponse()->getContent());

            $client->request('GET', '/videos/'.$draftVideo->getId());
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/videos/'.$publishedVideo->getId().'/thumbnail');
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/png');
            self::assertSame($thumbnailPng, $client->getResponse()->getContent());
            $client->request('GET', '/videos/'.$draftVideo->getId().'/thumbnail');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreSiteLocale($connection, $originalLocale);
            $entityManager->clear();
            $cleanupManager = $client->getContainer()->get(EntityManagerInterface::class);
            if ($cleanupManager instanceof EntityManagerInterface) {
                $connection = $cleanupManager->getConnection();
                $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $publishedSlug]);
                $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $draftSlug]);
                $connection->executeStatement('DELETE FROM news_articles WHERE slug = :slug', ['slug' => $memberOnlySlug]);
                $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $newsCategory->getId()]);
                $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $videoCategory->getId()]);
                $connection->executeStatement('DELETE FROM static_pages WHERE slug = :slug', ['slug' => $publishedPageSlug]);
                $connection->executeStatement('DELETE FROM static_pages WHERE slug = :slug', ['slug' => $draftPageSlug]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $publishedVideo->getId()]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $draftVideo->getId()]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $embeddedVideo->getId()]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $invalidEmbedVideo->getId()]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $streamVideo->getId()]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $liveVideo->getId()]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $unapprovedStreamVideo->getId()]);
            }
        }
    }

    public function testStaticPageAdministrationRequiresAnAdministrator(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/pages');

        self::assertResponseRedirects('/admin/login');
    }

    private function restoreSiteLocale(Connection $connection, ?string $originalLocale): void
    {
        if (null === $originalLocale) {
            $connection->executeStatement('DELETE FROM site_settings WHERE id = :id', ['id' => SiteSettings::SINGLETON_ID]);

            return;
        }

        $connection->executeStatement(
            'UPDATE site_settings SET default_locale = :locale WHERE id = :id',
            ['locale' => $originalLocale, 'id' => SiteSettings::SINGLETON_ID],
        );
    }
}
