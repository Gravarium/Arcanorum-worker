<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\ContentTag;
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
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PublicContentTest extends WebTestCase
{
    public function testCategoryPagesKeepFiltersAcrossPaginationAndExcludeDrafts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);
        $originalVideoPageLimit = $settingsProvider->current()->getPublicVideoPageLimit();
        $suffix = bin2hex(random_bytes(6));
        $tagName = 'Browse-'.$suffix;
        $tag = new ContentTag($tagName);
        $newsCategory = new ContentCategory('Browse news '.$suffix, ContentCategoryType::News);
        $newsCategory->updateTaxonomy('browse-news-'.$suffix, '', null, null, null, 0);
        $videoCategory = new ContentCategory('Browse videos '.$suffix, ContentCategoryType::Video);
        $videoCategory->updateTaxonomy('browse-videos-'.$suffix, '', null, null, null, 0);
        $articles = [];
        $videos = [];

        for ($index = 0; $index < 25; ++$index) {
            $article = new NewsArticle(
                'Browse article '.sprintf('%02d', $index).' '.$suffix,
                'browse-article-'.$suffix.'-'.$index,
                $newsCategory->getName(),
                'Public filtered summary.',
                'Public filtered body.',
                PublishingStatus::Published,
                false,
                false,
            );
            $article->replaceTags([$tag]);
            $articles[] = $article;

            $video = new Video(
                'Browse video '.sprintf('%02d', $index).' '.$suffix,
                'Public filtered description.',
                $videoCategory->getName(),
                VideoSourceType::Url,
                'https://video.example.test/browse-'.$suffix.'-'.$index.'.mp4',
                PublishingStatus::Published,
                false,
                false,
            );
            $video->replaceTags([$tag]);
            $videos[] = $video;
        }

        $draftArticle = new NewsArticle(
            'Browse draft article '.$suffix,
            'browse-draft-article-'.$suffix,
            $newsCategory->getName(),
            'Draft summary.',
            'Draft body.',
            PublishingStatus::Draft,
            false,
            false,
        );
        $draftArticle->replaceTags([$tag]);
        $draftVideo = new Video(
            'Browse draft video '.$suffix,
            'Draft description.',
            $videoCategory->getName(),
            VideoSourceType::Url,
            'https://video.example.test/browse-draft-'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            false,
        );
        $draftVideo->replaceTags([$tag]);
        $articles[] = $draftArticle;
        $videos[] = $draftVideo;

        try {
            $entityManager->persist($tag);
            $entityManager->persist($newsCategory);
            $entityManager->persist($videoCategory);
            foreach ($articles as $article) {
                $entityManager->persist($article);
            }
            foreach ($videos as $video) {
                $entityManager->persist($video);
            }
            $entityManager->flush();
            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['public_video_page_limit' => '24']));

            $newsPath = '/news/category/'.$newsCategory->getSlug().'?tag='.rawurlencode($tagName);
            $client->request('GET', $newsPath);
            self::assertResponseIsSuccessful();
            self::assertCount(24, $client->getCrawler()->filter('.content-grid article.content-card h2 a'));
            self::assertStringNotContainsString('Browse draft article '.$suffix, (string) $client->getResponse()->getContent());
            $newsNextPage = (string) $client->getCrawler()->filter('.pagination a')->last()->attr('href');
            self::assertSame('/news/category/'.$newsCategory->getSlug(), parse_url($newsNextPage, PHP_URL_PATH));
            parse_str((string) parse_url($newsNextPage, PHP_URL_QUERY), $newsQuery);
            self::assertSame($tagName, $newsQuery['tag'] ?? null);
            self::assertSame($newsCategory->getName(), $newsQuery['category'] ?? null);
            self::assertSame('2', $newsQuery['page'] ?? null);

            $client->request('GET', $newsNextPage);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $client->getCrawler()->filter('.content-grid article.content-card h2 a'));
            self::assertStringNotContainsString('Browse draft article '.$suffix, (string) $client->getResponse()->getContent());

            $videoPath = '/videos/category/'.$videoCategory->getSlug().'?tag='.rawurlencode($tagName);
            $client->request('GET', $videoPath);
            self::assertResponseIsSuccessful();
            self::assertCount(24, $client->getCrawler()->filter('.content-grid article.content-card h2 a'));
            self::assertStringNotContainsString('Browse draft video '.$suffix, (string) $client->getResponse()->getContent());
            $videoNextPage = (string) $client->getCrawler()->filter('.pagination a')->last()->attr('href');
            self::assertSame('/videos/category/'.$videoCategory->getSlug(), parse_url($videoNextPage, PHP_URL_PATH));
            parse_str((string) parse_url($videoNextPage, PHP_URL_QUERY), $videoQuery);
            self::assertSame($tagName, $videoQuery['tag'] ?? null);
            self::assertSame($videoCategory->getName(), $videoQuery['category'] ?? null);
            self::assertSame('2', $videoQuery['page'] ?? null);

            $client->request('GET', $videoNextPage);
            self::assertResponseIsSuccessful();
            self::assertCount(1, $client->getCrawler()->filter('.content-grid article.content-card h2 a'));
            self::assertStringNotContainsString('Browse draft video '.$suffix, (string) $client->getResponse()->getContent());
        } finally {
            $connection = $entityManager->getConnection();
            foreach ($articles as $article) {
                $connection->executeStatement('DELETE FROM news_articles WHERE id = :id', ['id' => $article->getId()]);
            }
            foreach ($videos as $video) {
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
            }
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $newsCategory->getId()]);
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $videoCategory->getId()]);
            $connection->executeStatement('DELETE FROM content_tags WHERE id = :id', ['id' => $tag->getId()]);
            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['public_video_page_limit' => (string) $originalVideoPageLimit]));
        }
    }

    public function testPublicPagesShowPublishedContentAndHideDrafts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(VideoStreamSourceResolver::class, new VideoStreamSourceResolver(['media.example.org']));
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);
        $connection = $entityManager->getConnection();
        $storedLocale = $connection->fetchOne('SELECT default_locale FROM site_settings WHERE id = :id', ['id' => SiteSettings::SINGLETON_ID]);
        $originalLocale = is_string($storedLocale) ? $storedLocale : null;
        $originalHomepageVideoLimit = $settingsProvider->current()->getHomepageVideoLimit();

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
        $audioStreamVideoTitle = 'Audio Stream '.$suffix;
        $audioFileVideoTitle = 'Audio File '.$suffix;
        $liveVideoTitle = 'Live Video '.$suffix;
        $unapprovedStreamTitle = 'Unapproved Stream '.$suffix;
        $newsCategoryName = 'News '.$suffix;
        $videoCategoryName = 'Video '.$suffix;
        $newsCategorySlug = 'news-'.$suffix;
        $videoCategorySlug = 'video-'.$suffix;
        $newsCategoryMetaDescription = 'News SEO <img src=x onerror="alert(1)"> '.$suffix;
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
        $audioStreamUrl = 'https://media.example.org/audio/program-'.$suffix.'.mp3?token=public-test';
        $audioStreamVideo = new Video(
            $audioStreamVideoTitle,
            'A direct MP3 audio stream on an allowlisted host.',
            $videoCategoryName,
            VideoSourceType::Stream,
            $audioStreamUrl,
            PublishingStatus::Published,
            true,
            true,
        );
        $audioFileBytes = "ID3\x04\x00\x00\x00\x00\x00\x00\xff\xfb\x90\x64".str_repeat("\x00", 4096);
        $audioFileObjectId = str_repeat('e', 64);
        $audioFileVideo = new Video(
            $audioFileVideoTitle,
            'A private MP3 upload served through the media route.',
            $videoCategoryName,
            VideoSourceType::File,
            '',
            PublishingStatus::Published,
            false,
            true,
        );
        $audioFileVideo->attachUploadedMedia($audioFileObjectId, 'audio/mpeg', strlen($audioFileBytes));
        $subtitleContents = "WEBVTT\n\n00:00.000 --> 00:02.000\nMobile subtitles\n";
        $subtitleStorageId = str_repeat('c', 64);
        $audioSubtitleStorageId = str_repeat('d', 64);
        $streamSubtitle = new VideoSubtitleTrack(
            $streamVideo,
            'en',
            'English',
            $subtitleStorageId,
            strlen($subtitleContents),
            hash('sha256', $subtitleContents),
        );
        $audioSubtitle = new VideoSubtitleTrack(
            $audioFileVideo,
            'en',
            'English audio captions',
            $audioSubtitleStorageId,
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
            ->expects($this->exactly(4))
            ->method('read')
            ->willReturnMap([
                [$publishedThumbnailId, strlen($thumbnailPng), $thumbnailPng],
                [$subtitleStorageId, strlen($subtitleContents), $subtitleContents],
                [$audioSubtitleStorageId, strlen($subtitleContents), $subtitleContents],
                [$audioFileObjectId, strlen($audioFileBytes), $audioFileBytes],
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
        $newsCategory->updateTaxonomy(
            $newsCategorySlug,
            'News archive <script>alert("taxonomy")</script> '.$suffix,
            'News SEO '.$suffix,
            $newsCategoryMetaDescription,
            'news, culture '.$suffix,
            5,
        );
        $videoCategory->updateTaxonomy(
            $videoCategorySlug,
            'Video archive '.$suffix,
            'Video SEO '.$suffix,
            'Videos from the archive '.$suffix,
            'video, archive '.$suffix,
            10,
        );
        $draftPage = new StaticPage(
            $draftPageTitle,
            $draftPageSlug,
            'Draft page summary',
            'Draft page body '.$suffix,
            PublishingStatus::Draft,
        );

        try {
            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['homepage_video_limit' => '24']));

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
            $entityManager->persist($audioStreamVideo);
            $entityManager->persist($audioFileVideo);
            $entityManager->persist($audioSubtitle);
            $entityManager->persist($streamSubtitle);
            $entityManager->persist($liveVideo);
            $entityManager->persist($unapprovedStreamVideo);
            $entityManager->flush();

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', $publishedArticleTitle);
            self::assertSelectorTextContains('main', $publishedVideoTitle);
            self::assertSame($audioStreamUrl, $client->getCrawler()->filter('audio[data-testid="home-featured-audio-stream"] source')->attr('src'));
            self::assertSame('audio/mpeg', $client->getCrawler()->filter('audio[data-testid="home-featured-audio-stream"] source')->attr('type'));
            self::assertSelectorNotExists('video[data-testid="home-featured-video"]');
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

            $client->request('GET', '/news/category/'.$newsCategorySlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $newsCategoryName);
            self::assertSelectorTextContains('main', $publishedArticleTitle);
            self::assertStringNotContainsString($draftArticleTitle, $client->getCrawler()->filter('main')->text());
            self::assertSelectorNotExists('main script');
            self::assertSelectorTextContains('.category-description', 'News archive');
            self::assertStringNotContainsString('alert(', $client->getCrawler()->filter('.category-description')->text());
            self::assertSelectorTextContains('title', 'News SEO '.$suffix);
            self::assertSame($newsCategoryMetaDescription, $client->getCrawler()->filter('meta[name="description"]')->attr('content'));
            self::assertSame('news, culture '.$suffix, $client->getCrawler()->filter('meta[name="keywords"]')->attr('content'));
            self::assertSame(
                'https://arcanorum.example.test/news/category/'.$newsCategorySlug,
                $client->getCrawler()->filter('link[rel="canonical"]')->attr('href'),
            );
            self::assertSelectorNotExists('head img');
            $client->request('GET', '/news/category/missing-category-'.$suffix);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
            $client->request('GET', '/videos/category/'.$newsCategorySlug);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

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

            $client->request('GET', '/videos/category/'.$videoCategorySlug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', $videoCategoryName);
            self::assertSelectorTextContains('main', $publishedVideoTitle);
            self::assertStringNotContainsString($draftVideoTitle, $client->getCrawler()->filter('main')->text());
            self::assertSame('Video SEO '.$suffix, $client->getCrawler()->filter('meta[property="og:title"]')->attr('content'));
            self::assertSame(
                'https://arcanorum.example.test/videos/category/'.$videoCategorySlug,
                $client->getCrawler()->filter('link[rel="canonical"]')->attr('href'),
            );

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
                "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com https://geo.dailymotion.com https://www.facebook.com https://drive.google.com https://rutube.ru https://vk.com https://player.youku.com",
                $embedPolicy,
            );
            self::assertStringNotContainsString('*', $embedPolicy);
            self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'));

            $client->request('GET', '/videos/'.$audioStreamVideo->getId());
            self::assertResponseIsSuccessful();
            self::assertSame($audioStreamUrl, $client->getCrawler()->filter('audio[data-testid="video-audio-stream"] source')->attr('src'));
            self::assertSame('audio/mpeg', $client->getCrawler()->filter('audio[data-testid="video-audio-stream"] source')->attr('type'));
            self::assertSame($audioStreamUrl, $client->getCrawler()->filter('a[data-testid="video-stream-fallback"]')->attr('href'));
            self::assertSelectorNotExists('video[data-testid="video-stream"]');
            self::assertSelectorNotExists('[data-video-player-engine="videojs"]');
            self::assertStringContainsString("media-src 'self' https://media.example.org", $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));

            $client->request('GET', '/videos/'.$audioFileVideo->getId());
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('audio[data-testid="video-audio-file"] source[type="audio/mpeg"]');
            self::assertSelectorExists('audio[data-testid="video-audio-file"] track[kind="subtitles"][srclang="en"][default]');
            self::assertSelectorNotExists('video[data-video-player-engine="videojs"]');
            $audioSubtitleTrack = $client->getCrawler()->filter('audio[data-testid="video-audio-file"] track[kind="subtitles"]');
            $audioSubtitlePath = $audioSubtitleTrack->attr('src');
            self::assertIsString($audioSubtitlePath);
            $client->request('GET', $audioSubtitlePath);
            self::assertResponseIsSuccessful();
            self::assertSame($subtitleContents, $client->getResponse()->getContent());
            $client->request('GET', '/videos/'.$audioFileVideo->getId());
            self::assertResponseIsSuccessful();
            $client->request('GET', '/videos/'.$audioFileVideo->getId().'/media', server: ['HTTP_RANGE' => 'bytes=0-3']);
            self::assertResponseStatusCodeSame(206);
            self::assertResponseHeaderSame('Content-Type', 'audio/mpeg');
            self::assertResponseHeaderSame('Content-Range', 'bytes 0-3/'.strlen($audioFileBytes));
            self::assertSame(substr($audioFileBytes, 0, 4), $client->getResponse()->getContent());

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
            $connection->beginTransaction();
            try {
                $connection->executeStatement(
                    'ALTER TABLE video_subtitle_tracks DROP CONSTRAINT video_subtitle_tracks_size_check, DROP CONSTRAINT video_subtitle_tracks_sha256_check, DROP CONSTRAINT video_subtitle_tracks_storage_object_id_check',
                );
                $connection->executeStatement(
                    'UPDATE video_subtitle_tracks SET size = :size WHERE id = :id',
                    ['size' => 1048577, 'id' => $streamSubtitle->getId()],
                );
                $entityManager->clear();
                $client->request('GET', '/videos/'.$streamVideo->getId().'/subtitles/'.$streamSubtitle->getId());
                self::assertResponseStatusCodeSame(404);

                $connection->executeStatement(
                    'UPDATE video_subtitle_tracks SET size = :size, sha256 = :sha256 WHERE id = :id',
                    ['size' => strlen($subtitleContents), 'sha256' => str_repeat('A', 64), 'id' => $streamSubtitle->getId()],
                );
                $entityManager->clear();
                $client->request('GET', '/videos/'.$streamVideo->getId().'/subtitles/'.$streamSubtitle->getId());
                self::assertResponseStatusCodeSame(404);

                $connection->executeStatement(
                    'UPDATE video_subtitle_tracks SET sha256 = :sha256, storage_object_id = :object_id WHERE id = :id',
                    [
                        'sha256' => hash('sha256', $subtitleContents),
                        'object_id' => 'A'.substr($subtitleStorageId, 1),
                        'id' => $streamSubtitle->getId(),
                    ],
                );
                $entityManager->clear();
                $client->request('GET', '/videos/'.$streamVideo->getId().'/subtitles/'.$streamSubtitle->getId());
                self::assertResponseStatusCodeSame(404);
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $entityManager->clear();
            }
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
            $connection->beginTransaction();
            try {
                $connection->executeStatement('ALTER TABLE videos DROP CONSTRAINT chk_videos_thumbnail_metadata');
                $connection->executeStatement(
                    'UPDATE videos SET thumbnail_object_id = :object_id WHERE id = :id',
                    ['object_id' => 'A'.substr($publishedThumbnailId, 1), 'id' => $publishedVideo->getId()],
                );
                $entityManager->clear();
                $client->request('GET', '/videos/'.$publishedVideo->getId().'/thumbnail');
                self::assertResponseStatusCodeSame(404);
                self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));

                $connection->executeStatement(
                    'UPDATE videos SET thumbnail_object_id = :object_id, thumbnail_size = :size WHERE id = :id',
                    ['object_id' => $publishedThumbnailId, 'size' => 4194305, 'id' => $publishedVideo->getId()],
                );
                $entityManager->clear();
                $client->request('GET', '/videos/'.$publishedVideo->getId().'/thumbnail');
                self::assertResponseStatusCodeSame(404);

                $connection->executeStatement(
                    'UPDATE videos SET thumbnail_size = :size, thumbnail_mime_type = :mime WHERE id = :id',
                    ['size' => strlen($thumbnailPng), 'mime' => 'image/svg+xml', 'id' => $publishedVideo->getId()],
                );
                $entityManager->clear();
                $client->request('GET', '/videos/'.$publishedVideo->getId().'/thumbnail');
                self::assertResponseStatusCodeSame(404);
            } finally {
                if ($connection->isTransactionActive()) {
                    $connection->rollBack();
                }
                $entityManager->clear();
            }
            $client->request('GET', '/videos/'.$draftVideo->getId().'/thumbnail');
            self::assertResponseStatusCodeSame(404);
        } finally {
            $this->restoreSiteLocale($connection, $originalLocale);
            $entityManager->clear();
            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'homepage_video_limit' => (string) $originalHomepageVideoLimit,
            ]));
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
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $audioStreamVideo->getId()]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $audioFileVideo->getId()]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $liveVideo->getId()]);
                $connection->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $unapprovedStreamVideo->getId()]);
            }
        }
    }

    public function testNewsShareLinksUseTheConfiguredCanonicalUrlAndRespectVisibilitySettings(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);
        $originalShareSetting = $settingsProvider->current()->isVideoShareWidgetEnabled() ? '1' : '0';

        $suffix = bin2hex(random_bytes(6));
        $slug = 'share-news-'.$suffix;
        $title = 'News "share" & Grüße '.$suffix;
        $publicArticle = new NewsArticle(
            $title,
            $slug,
            null,
            'A public article summary.',
            'A public article body.',
            PublishingStatus::Published,
            false,
            false,
        );
        $membersOnlyArticle = new NewsArticle(
            'Private share test '.$suffix,
            'private-share-news-'.$suffix,
            null,
            'Members-only article summary.',
            'Members-only article body.',
            PublishingStatus::Published,
            false,
            false,
            'Redaktion',
            true,
        );

        try {
            $entityManager->persist($publicArticle);
            $entityManager->persist($membersOnlyArticle);
            $entityManager->flush();
            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['video_share_widget_enabled' => '1']));

            $client->request('GET', '/news/'.$slug, server: ['HTTP_HOST' => 'attacker.example.test']);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('#news-share-heading', 'Diesen Inhalt teilen');
            self::assertCount(3, $client->getCrawler()->filter('#news-share [data-testid^="share-"]'));
            self::assertSelectorExists('#news-share a[data-testid="public-share-url"][href="/news/'.$slug.'"]');
            self::assertSelectorExists('#news-share a[data-testid="share-facebook"][target="_blank"][rel="nofollow noopener noreferrer"][referrerpolicy="no-referrer"]');
            self::assertSelectorExists('#news-share a[data-testid="share-x"][target="_blank"][rel="nofollow noopener noreferrer"][referrerpolicy="no-referrer"]');
            self::assertSelectorExists('#news-share a[data-testid="share-pinterest"][target="_blank"][rel="nofollow noopener noreferrer"][referrerpolicy="no-referrer"]');

            $twitterHref = $client->getCrawler()->filter('#news-share [data-testid="share-x"]')->attr('href');
            self::assertIsString($twitterHref);
            $twitterQuery = [];
            parse_str((string) parse_url($twitterHref, PHP_URL_QUERY), $twitterQuery);
            self::assertSame('https://arcanorum.example.test/news/'.$slug, $twitterQuery['url'] ?? null);
            self::assertSame($title, $twitterQuery['text'] ?? null);
            self::assertStringNotContainsString('attacker.example.test', $twitterHref);
            self::assertStringNotContainsString('platform.twitter.com/widgets.js', (string) $client->getResponse()->getContent());

            $client->request('GET', '/news/private-share-news-'.$suffix);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('#news-share');

            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['video_share_widget_enabled' => '0']));
            $client->request('GET', '/news/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('#news-share');
        } finally {
            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'video_share_widget_enabled' => $originalShareSetting,
            ]));
            $connection = $entityManager->getConnection();
            $connection->executeStatement('DELETE FROM news_articles WHERE id IN (:public, :members)', [
                'public' => $publicArticle->getId(),
                'members' => $membersOnlyArticle->getId(),
            ]);
            $entityManager->clear();
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
