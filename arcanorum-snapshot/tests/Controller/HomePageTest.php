<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CommentContentType;
use App\Entity\CommentProvider;
use App\Entity\ContentComment;
use App\Entity\ContentTag;
use App\Entity\FeatureModule;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoQuality;
use App\Entity\VideoQualitySource;
use App\Entity\VideoSourceType;
use App\Module\Video\Service\VideoStreamSourceResolver;
use App\Platform\Hosting\HostingCachePoolProvider;
use App\Repository\ContentTagRepository;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

final class HomePageTest extends WebTestCase
{
    public function testFrameworkApplicationCacheUsesHostingProvider(): void
    {
        static::createClient();

        $cache = static::getContainer()->get('cache.app');

        self::assertInstanceOf(HostingCachePoolProvider::class, $cache);
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        self::assertInstanceOf(CacheInterface::class, $cache);
        self::assertInstanceOf(NamespacedPoolInterface::class, $cache);

        $key = 'framework-cache-'.bin2hex(random_bytes(8));
        $item = $cache->getItem($key);
        $item->set('active');
        self::assertTrue($cache->save($item));
        self::assertSame('active', $cache->getItem($key)->get());
        self::assertTrue($cache->deleteItem($key));
    }

    public function testPlatformHomePageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorExists('.portal-home[data-testid="home-portal"]');
        self::assertSelectorExists('.portal-home > header.public-header nav.public-navigation');
        self::assertSelectorExists('.portal-home > .portal-hero img.portal-hero-image[src*="portal-highlands.webp"]');
        self::assertSelectorExists('.portal-layout[data-testid="home-portal-layout"]');
        self::assertSelectorExists('.portal-layout > aside[data-testid="home-left-rail"]');
        self::assertSelectorExists('.portal-layout > main[data-testid="home-main-column"]');
        self::assertSelectorExists('.portal-layout > aside[data-testid="home-right-rail"]');
        self::assertSelectorTextContains('h1', 'Betritt eine');
        self::assertSelectorTextContains('h1', 'Geschichten.');
        self::assertSelectorTextContains('.portal-hero-intro', 'News, Gilden, Welten');
        self::assertSelectorExists('link[rel="stylesheet"][href*="portal-home.css?v=20261106-2"]');
        self::assertSelectorTextContains('main', 'Neueste Videos');
    }

    public function testHomeCardsUseLocalArtworkWithoutExposingMemberThumbnails(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);

        $settings = $settingsProvider->current();
        $originalVideoLimit = $settings->getHomepageVideoLimit();
        $originalNewsLimit = $settings->getHomepageNewsLimit();
        $originalGeneralSettings = [
            'site_name' => $settings->getSiteName(),
            'tagline' => $settings->getTagline(),
            'locale' => $settings->getDefaultLocale(),
            'timezone' => $settings->getTimezone(),
        ];
        $originalCommentSettings = [
            'comment_provider' => $settings->getCommentProvider()->value,
            'facebook_comments_app_id' => $settings->getFacebookCommentsAppId(),
            'disqus_comments_shortname' => $settings->getDisqusCommentsShortname(),
            'cookie_consent_enabled' => $settings->isCookieConsentEnabled() ? '1' : '0',
        ];
        $originalFeatureModules = [];
        foreach (FeatureModule::cases() as $module) {
            $originalFeatureModules[$module->inputName()] = $settings->isFeatureModuleEnabled($module) ? '1' : '0';
        }
        $suffix = bin2hex(random_bytes(6));
        $publicVideoWithThumbnail = new Video(
            'Public home video thumbnail '.$suffix,
            'A public home video with its own preview image.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/public-thumbnail-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $publicVideoWithThumbnail->attachThumbnail(hash('sha256', 'public-video-'.$suffix), 'image/webp', 128);
        $publicVideoWithoutThumbnail = new Video(
            'Public home video artwork '.$suffix,
            'A public home video without a preview image.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/public-artwork-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $membersOnlyVideo = new Video(
            'Members home video artwork '.$suffix,
            'A members-only home video with a private preview image.',
            null,
            VideoSourceType::Url,
            'https://video.example.test/private-thumbnail-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $membersOnlyVideo->updateEditorialMetadata('members-home-'.$suffix, 'Redaktion', true, false);
        $membersOnlyVideo->attachThumbnail(hash('sha256', 'private-video-'.$suffix), 'image/webp', 128);
        $publicVideoWithThumbnail->setPublicationAt(new \DateTimeImmutable('-2 minutes', new \DateTimeZone('UTC')));
        $publicVideoWithoutThumbnail->setPublicationAt(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')));
        $membersOnlyVideo->setPublicationAt(new \DateTimeImmutable('-5 seconds', new \DateTimeZone('UTC')));
        $videos = [$publicVideoWithThumbnail, $publicVideoWithoutThumbnail, $membersOnlyVideo];

        $publicArticleWithThumbnail = new NewsArticle(
            'Public home news thumbnail '.$suffix,
            'public-home-thumbnail-'.$suffix,
            'Portal',
            'A public news item with its own preview image.',
            'Public news body.',
            PublishingStatus::Published,
            false,
            true,
            'Editorial Desk',
        );
        $publicArticleWithThumbnail->attachThumbnail(hash('sha256', 'public-news-'.$suffix), 'image/webp', 128);
        $publicArticleWithoutThumbnail = new NewsArticle(
            'Public home news artwork '.$suffix,
            'public-home-artwork-'.$suffix,
            'Portal',
            'A public news item without a preview image.',
            'Public news body.',
            PublishingStatus::Published,
            false,
            true,
        );
        $membersOnlyArticle = new NewsArticle(
            'Members home news artwork '.$suffix,
            'members-home-news-'.$suffix,
            'Portal',
            'A members-only news item with a private preview image.',
            'Private news body.',
            PublishingStatus::Published,
            false,
            true,
            membersOnly: true,
        );
        $membersOnlyArticle->attachThumbnail(hash('sha256', 'private-news-'.$suffix), 'image/webp', 128);
        $publicArticleWithThumbnail->setPublicationAt(new \DateTimeImmutable('-2 minutes', new \DateTimeZone('UTC')));
        $publicArticleWithoutThumbnail->setPublicationAt(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')));
        $membersOnlyArticle->setPublicationAt(new \DateTimeImmutable('-5 seconds', new \DateTimeZone('UTC')));
        $articles = [$publicArticleWithThumbnail, $publicArticleWithoutThumbnail, $membersOnlyArticle];
        $publicApprovedComment = new ContentComment(
            CommentContentType::News,
            $publicArticleWithoutThumbnail->getId(),
            $publicArticleWithoutThumbnail->getTitle(),
            'Reader',
            'Approved comment counted on the public homepage card.',
        );
        $publicApprovedComment->approve();
        $publicPendingComment = new ContentComment(
            CommentContentType::News,
            $publicArticleWithoutThumbnail->getId(),
            $publicArticleWithoutThumbnail->getTitle(),
            'Reader',
            'Pending comment must not affect the public count.',
        );
        $membersOnlyApprovedComment = new ContentComment(
            CommentContentType::News,
            $membersOnlyArticle->getId(),
            $membersOnlyArticle->getTitle(),
            'Reader',
            'Member-only comment must not produce a public count.',
        );
        $membersOnlyApprovedComment->approve();
        $comments = [$publicApprovedComment, $publicPendingComment, $membersOnlyApprovedComment];
        $enabledFeatureModules = $originalFeatureModules;
        $enabledFeatureModules[FeatureModule::News->inputName()] = '1';
        $enabledFeatureModules[FeatureModule::Comments->inputName()] = '1';

        try {
            self::assertSame([], $settingsManager->updateFeatureModules($enabledFeatureModules));
            self::assertSame([], $settingsManager->update([
                ...$originalGeneralSettings,
                ...$originalCommentSettings,
                'comment_provider' => CommentProvider::Native->value,
            ]));
            foreach ([...$videos, ...$articles, ...$comments] as $content) {
                $entityManager->persist($content);
            }
            $entityManager->flush();
            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'homepage_video_limit' => '24',
                'homepage_news_limit' => '12',
            ]));

            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            $crawler = $client->getCrawler();
            $publicVideoCard = $crawler->filterXPath(sprintf(
                '//article[contains(concat(" ", normalize-space(@class), " "), " content-card ")][.//a[@href="/videos/%s"]]',
                $publicVideoWithThumbnail->getId(),
            ));
            self::assertSame(1, $publicVideoCard->count());
            self::assertSame(
                '/videos/'.$publicVideoWithThumbnail->getId().'/thumbnail',
                $publicVideoCard->filter('img.video-thumbnail')->attr('src'),
            );

            $publicArticleCard = $crawler->filterXPath(sprintf(
                '//article[contains(concat(" ", normalize-space(@class), " "), " content-card ")][.//a[@href="/news/%s"]]',
                $publicArticleWithThumbnail->getSlug(),
            ));
            self::assertSame(1, $publicArticleCard->count());
            self::assertSame(
                '/news/'.$publicArticleWithThumbnail->getId().'/thumbnail',
                $publicArticleCard->filter('img.video-thumbnail')->attr('src'),
            );
            self::assertSame(1, $publicArticleCard->filter('.news-article-meta .byline')->count());
            self::assertStringContainsString('Editorial Desk', $publicArticleCard->filter('.news-article-meta')->text());
            self::assertSame(1, $publicArticleCard->filter('.news-article-meta time')->count());
            self::assertSame(1, $publicArticleCard->filter('.news-article-meta .news-reading-time')->count());
            self::assertStringEndsWith('0', trim($publicArticleCard->filter('.news-article-meta .news-comment-count')->text()));

            foreach ([
                [
                    'card' => $crawler->filterXPath(sprintf(
                        '//article[contains(concat(" ", normalize-space(@class), " "), " content-card ")][.//a[@href="/videos/%s"]]',
                        $publicVideoWithoutThumbnail->getId(),
                    )),
                    'label' => 'public video without thumbnail',
                ],
                [
                    'card' => $crawler->filterXPath(sprintf(
                        '//article[contains(concat(" ", normalize-space(@class), " "), " content-card ")][.//a[@href="/videos/%s"]]',
                        $membersOnlyVideo->getId(),
                    )),
                    'label' => 'members-only video',
                ],
                [
                    'card' => $crawler->filterXPath(sprintf(
                        '//article[contains(concat(" ", normalize-space(@class), " "), " content-card ")][.//a[@href="/news/%s"]]',
                        $membersOnlyArticle->getSlug(),
                    )),
                    'label' => 'members-only news',
                ],
            ] as $fallback) {
                self::assertSame(1, $fallback['card']->count(), sprintf('Expected a homepage card for %s.', $fallback['label']));
                $artwork = $fallback['card']->filter('img.portal-card-art[alt=""][aria-hidden="true"]');
                self::assertSame(1, $artwork->count(), sprintf('Expected decorative local artwork for %s.', $fallback['label']));
                self::assertStringContainsString('portal-', (string) $artwork->attr('src'));
            }

            $homeFocusStory = $crawler->filter('[data-testid="home-focus-story"]');
            self::assertSame(1, $homeFocusStory->count());
            self::assertSame(
                '/news/'.$publicArticleWithoutThumbnail->getSlug(),
                $homeFocusStory->filter('.portal-feature-art')->attr('href'),
            );
            self::assertStringContainsString(
                'portal-fortress.webp',
                (string) $homeFocusStory->filter('.portal-feature-art img')->attr('src'),
            );
            self::assertSame(1, $homeFocusStory->filter('.news-article-meta .byline')->count());
            self::assertSame(1, $homeFocusStory->filter('.news-article-meta time')->count());
            self::assertSame(1, $homeFocusStory->filter('.news-article-meta .news-reading-time')->count());
            self::assertStringEndsWith('1', trim($homeFocusStory->filter('.news-article-meta .news-comment-count')->text()));
            self::assertSame(0, $crawler->filterXPath(sprintf(
                '//article[contains(concat(" ", normalize-space(@class), " "), " content-card ")][.//a[@href="/news/%s"]]',
                $publicArticleWithoutThumbnail->getSlug(),
            ))->count());
            $membersNewsCard = $crawler->filterXPath(sprintf(
                '//article[contains(concat(" ", normalize-space(@class), " "), " content-card ")][.//a[@href="/news/%s"]]',
                $membersOnlyArticle->getSlug(),
            ));
            self::assertSame(0, $membersNewsCard->filter('.news-article-meta .news-comment-count')->count());

            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString('/videos/'.$membersOnlyVideo->getId().'/thumbnail', $html);
            self::assertStringNotContainsString('/news/'.$membersOnlyArticle->getId().'/thumbnail', $html);

            $disabledFeatureModules = $enabledFeatureModules;
            $disabledFeatureModules[FeatureModule::Comments->inputName()] = '0';
            self::assertSame([], $settingsManager->updateFeatureModules($disabledFeatureModules));
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.portal-home .news-comment-count');

            self::assertSame([], $settingsManager->updateFeatureModules($enabledFeatureModules));
            self::assertSame([], $settingsManager->update([
                ...$originalGeneralSettings,
                'comment_provider' => CommentProvider::Disqus->value,
                'disqus_comments_shortname' => 'homepage-news-test',
                'cookie_consent_enabled' => '1',
            ]));
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('.portal-home .news-comment-count');
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM content_comments WHERE id IN (:public_approved, :public_pending, :members_approved)',
                [
                    'public_approved' => $publicApprovedComment->getId(),
                    'public_pending' => $publicPendingComment->getId(),
                    'members_approved' => $membersOnlyApprovedComment->getId(),
                ],
            );
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM videos WHERE id IN (:first, :second, :third)',
                ['first' => $publicVideoWithThumbnail->getId(), 'second' => $publicVideoWithoutThumbnail->getId(), 'third' => $membersOnlyVideo->getId()],
            );
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM news_articles WHERE id IN (:first, :second, :third)',
                ['first' => $publicArticleWithThumbnail->getId(), 'second' => $publicArticleWithoutThumbnail->getId(), 'third' => $membersOnlyArticle->getId()],
            );
            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'homepage_video_limit' => (string) $originalVideoLimit,
                'homepage_news_limit' => (string) $originalNewsLimit,
            ]));
            self::assertSame([], $settingsManager->updateFeatureModules($originalFeatureModules));
            self::assertSame([], $settingsManager->update([...$originalGeneralSettings, ...$originalCommentSettings]));
        }
    }

    public function testFeaturedPublicNewsBecomesTheHomeLeadWithoutDuplicatingTheLatestStory(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);

        $settings = $settingsProvider->current();
        $originalNewsLimit = $settings->getHomepageNewsLimit();
        $originalFeatureModules = [];
        foreach (FeatureModule::cases() as $module) {
            $originalFeatureModules[$module->inputName()] = $settings->isFeatureModuleEnabled($module) ? '1' : '0';
        }
        $suffix = bin2hex(random_bytes(6));
        $latestPublicArticle = new NewsArticle(
            'Latest public homepage news '.$suffix,
            'latest-public-homepage-news-'.$suffix,
            'News',
            'The latest public article must remain in the ticker.',
            'Latest public article body.',
            PublishingStatus::Published,
            false,
            true,
            'Latest Desk',
        );
        $featuredPublicArticle = new NewsArticle(
            'Featured public homepage news '.$suffix,
            'featured-public-homepage-news-'.$suffix,
            'News',
            'An older article explicitly selected by the editorial team.',
            'Featured public article body.',
            PublishingStatus::Published,
            true,
            true,
            'Featured Desk',
        );
        $olderFeaturedPublicArticle = new NewsArticle(
            'Older featured homepage news '.$suffix,
            'older-featured-homepage-news-'.$suffix,
            'News',
            'An older editorial selection.',
            'Older featured public article body.',
            PublishingStatus::Published,
            true,
            true,
            'Older Desk',
        );
        $membersOnlyFeaturedArticle = new NewsArticle(
            'Private featured homepage news '.$suffix,
            'private-featured-homepage-news-'.$suffix,
            'News',
            'Private article summary.',
            'Private featured article body.',
            PublishingStatus::Published,
            true,
            true,
            'Private Desk',
            membersOnly: true,
        );
        $futureFeaturedArticle = new NewsArticle(
            'Future featured homepage news '.$suffix,
            'future-featured-homepage-news-'.$suffix,
            'News',
            'A scheduled editorial selection.',
            'Future featured article body.',
            PublishingStatus::Published,
            true,
            true,
            'Future Desk',
        );
        $latestPublicArticle->setPublicationAt(new \DateTimeImmutable('-5 seconds', new \DateTimeZone('UTC')));
        $featuredPublicArticle->setPublicationAt(new \DateTimeImmutable('-15 seconds', new \DateTimeZone('UTC')));
        $olderFeaturedPublicArticle->setPublicationAt(new \DateTimeImmutable('-30 seconds', new \DateTimeZone('UTC')));
        $membersOnlyFeaturedArticle->setPublicationAt(new \DateTimeImmutable('-1 second', new \DateTimeZone('UTC')));
        $futureFeaturedArticle->setPublicationAt(new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')));
        $articles = [
            $latestPublicArticle,
            $featuredPublicArticle,
            $olderFeaturedPublicArticle,
            $membersOnlyFeaturedArticle,
            $futureFeaturedArticle,
        ];
        $enabledFeatureModules = $originalFeatureModules;
        $enabledFeatureModules[FeatureModule::News->inputName()] = '1';

        try {
            self::assertSame([], $settingsManager->updateFeatureModules($enabledFeatureModules));
            foreach ($articles as $article) {
                $entityManager->persist($article);
            }
            $entityManager->flush();
            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'homepage_news_limit' => '2',
            ]));

            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            $crawler = $client->getCrawler();
            $homeFocusStory = $crawler->filter('[data-testid="home-focus-story"]');
            self::assertSame(
                '/news/'.$featuredPublicArticle->getSlug(),
                $homeFocusStory->filter('.portal-feature-art')->attr('href'),
            );
            self::assertSame(
                '/news/'.$latestPublicArticle->getSlug(),
                $crawler->filter('[data-testid="home-latest-ticker"] a')->attr('href'),
            );
            self::assertSame(1, $crawler->filterXPath(sprintf(
                '//article[contains(concat(" ", normalize-space(@class), " "), " content-card ")][.//a[@href="/news/%s"]]',
                $latestPublicArticle->getSlug(),
            ))->count());
            self::assertSame(0, $crawler->filterXPath(sprintf(
                '//article[contains(concat(" ", normalize-space(@class), " "), " content-card ")][.//a[@href="/news/%s"]]',
                $featuredPublicArticle->getSlug(),
            ))->count());
            self::assertSame(1, $homeFocusStory->filter('.news-article-meta .byline')->count());
            self::assertStringContainsString(
                'Featured Desk',
                $homeFocusStory->filter('.news-article-meta')->text(),
            );
            self::assertSame(1, $homeFocusStory->filter('.news-article-meta .news-reading-time')->count());
            self::assertStringNotContainsString($membersOnlyFeaturedArticle->getTitle(), $homeFocusStory->text());
            self::assertStringNotContainsString($futureFeaturedArticle->getTitle(), (string) $client->getResponse()->getContent());
        } finally {
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM news_articles WHERE id IN (:latest, :featured, :older_featured, :members_featured, :future_featured)',
                [
                    'latest' => $latestPublicArticle->getId(),
                    'featured' => $featuredPublicArticle->getId(),
                    'older_featured' => $olderFeaturedPublicArticle->getId(),
                    'members_featured' => $membersOnlyFeaturedArticle->getId(),
                    'future_featured' => $futureFeaturedArticle->getId(),
                ],
            );
            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'homepage_news_limit' => (string) $originalNewsLimit,
            ]));
            self::assertSame([], $settingsManager->updateFeatureModules($originalFeatureModules));
        }
    }

    public function testNewestHomeVideosKeepAStableOrderWhenPublicationTimesMatch(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);
        $originalLimit = $settingsProvider->current()->getHomepageVideoLimit();
        $suffix = bin2hex(random_bytes(6));
        $videos = [
            new Video(
                'Stable home video A '.$suffix,
                'Verifies deterministic ordering for equally recent videos.',
                null,
                VideoSourceType::Url,
                'https://video.example.test/stable-home-a-'.$suffix.'.mp4',
                PublishingStatus::Published,
                false,
                true,
            ),
            new Video(
                'Stable home video B '.$suffix,
                'Verifies deterministic ordering for equally recent videos.',
                null,
                VideoSourceType::Url,
                'https://video.example.test/stable-home-b-'.$suffix.'.mp4',
                PublishingStatus::Published,
                false,
                true,
            ),
        ];

        try {
            foreach ($videos as $video) {
                $entityManager->persist($video);
            }
            $entityManager->flush();
            $entityManager->getConnection()->executeStatement(
                "UPDATE videos SET published_at = CURRENT_TIMESTAMP - INTERVAL '1 day' WHERE id IN (:first, :second)",
                ['first' => $videos[0]->getId(), 'second' => $videos[1]->getId()],
            );
            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['homepage_video_limit' => '24']));

            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            $videoLinks = $client->getCrawler()->filter('section[aria-labelledby="home-videos-heading"] .content-grid h3 a');
            $expectedIds = array_map(static fn (Video $video): string => $video->getId(), $videos);
            sort($expectedIds, SORT_STRING);
            $actualIds = [];
            foreach ($videoLinks as $link) {
                if (!$link instanceof \DOMElement) {
                    self::fail('The newest video link must be an HTML element.');
                }
                $id = basename($link->getAttribute('href'));
                if (in_array($id, $expectedIds, true)) {
                    $actualIds[] = $id;
                }
            }
            self::assertSame($expectedIds, $actualIds);
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id IN (:first, :second)', [
                'first' => $videos[0]->getId(),
                'second' => $videos[1]->getId(),
            ]);
            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['homepage_video_limit' => (string) $originalLimit]));
        }
    }

    public function testFeaturedHomeVideoUsesApprovedStreamAndMutedAutoplaySettings(): void
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
        $originalSettings = $settingsProvider->current();
        $originalWidth = $originalSettings->getVideoPlayerHomeMaxWidth()->value;
        $originalEngine = $originalSettings->getVideoPlayerEngine()->value;
        $originalAutoplay = $originalSettings->isPlayerFeaturedAutoplayEnabled() ? '1' : '0';
        $video = new Video(
            'Featured home stream '.bin2hex(random_bytes(5)),
            'Approved stream used by the public home player test.',
            null,
            VideoSourceType::Stream,
            'https://media.example.org/home-featured.m3u8',
            PublishingStatus::Published,
            true,
            true,
        );

        try {
            $entityManager->persist($video);
            $entityManager->flush();
            self::assertSame([], $settingsManager->updateContentOptions([
                'video_player_engine' => 'videojs',
                'video_player_home_max_width' => '800',
                'player_featured_autoplay_enabled' => '1',
            ]));

            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('video[data-testid="home-featured-video"][autoplay][muted][data-player-max-width="800"][data-video-player-engine="videojs"]');
            self::assertSelectorExists('script[src*="videojs-player.js"]');
            self::assertSelectorExists('link[href*="video-js.min.css"]');
            self::assertSelectorExists('video[data-hls-url="https://media.example.org/home-featured.m3u8"]');
            self::assertSelectorNotExists('select[data-video-quality-selector]');
            self::assertStringContainsString(
                "media-src 'self' https://media.example.org",
                (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'),
            );
            self::assertSame(0, $client->getCrawler()->filter(sprintf('.content-grid a[href="/videos/%s"]', $video->getId()))->count());
        } finally {
            $entityManager->remove($video);
            $entityManager->flush();
            self::assertSame([], $settingsManager->updateContentOptions([
                'video_player_engine' => $originalEngine,
                'video_player_home_max_width' => $originalWidth,
                'player_featured_autoplay_enabled' => $originalAutoplay,
            ]));
        }
    }

    public function testFeaturedUploadedVideoOffersItsApprovedQualitySources(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(
            VideoStreamSourceResolver::class,
            new VideoStreamSourceResolver(['cdn.example.net']),
        );
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $suffix = bin2hex(random_bytes(5));
        $video = new Video(
            'Featured file quality '.$suffix,
            'A featured private file with an approved alternate media quality.',
            null,
            VideoSourceType::File,
            '',
            PublishingStatus::Published,
            true,
            false,
        );
        $video->attachUploadedMedia(bin2hex(random_bytes(32)), 'video/mp4', 1024);
        $qualitySource = new VideoQualitySource(
            $video,
            VideoQuality::P720,
            'https://cdn.example.net/home-'.$suffix.'-720.mp4',
        );

        try {
            $entityManager->persist($video);
            $entityManager->persist($qualitySource);
            $entityManager->flush();

            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('video#video-player-'.$video->getId());
            self::assertSelectorExists('select[data-video-quality-selector] option[value="https://cdn.example.net/home-'.$suffix.'-720.mp4"]');
            self::assertSelectorExists('script[src*="video-quality-selector.js"]');
            self::assertStringContainsString('https://cdn.example.net', (string) $client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertTrue($client->getResponse()->headers->getCacheControlDirective('no-store'));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
        }
    }

    public function testUnapprovedFeaturedStreamIsNotRenderedAsAPlayerOrLeaked(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $client->getContainer()->set(VideoStreamSourceResolver::class, new VideoStreamSourceResolver(['media.example.org']));
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $video = new Video(
            'Unapproved featured stream '.bin2hex(random_bytes(5)),
            'A stream whose host must not be rendered on the public home page.',
            null,
            VideoSourceType::Stream,
            'https://unapproved.example.org/private.m3u8',
            PublishingStatus::Published,
            true,
            true,
        );

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('[data-testid="home-featured-video-section"]');
            self::assertSelectorNotExists('video[data-testid="home-featured-video"]');
            self::assertSelectorTextContains('[data-testid="home-featured-video-section"]', 'kann auf der Startseite nicht direkt abgespielt werden');
            self::assertStringNotContainsString('unapproved.example.org', (string) $client->getResponse()->getContent());
        } finally {
            $entityManager->remove($video);
            $entityManager->flush();
        }
    }

    public function testPublicNavigationLeadsGuestsToMemberLoginAndRegistration(): void
    {
        $client = static::createClient();

        foreach (['/', '/news', '/videos'] as $path) {
            $client->request('GET', $path);

            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form.public-header-search[role="search"] input[name="q"]');
            self::assertSelectorExists('nav.public-navigation');
            self::assertSelectorExists('details.public-account > summary');
            self::assertSelectorExists('.public-account-menu a[href="/konto/anmelden"]');
            self::assertSelectorExists('.public-account-menu a[href="/konto/registrieren"]');
            self::assertSelectorTextContains('.public-account-menu a[href="/admin"]', 'Verwaltung');
        }

        $client->request('GET', '/konto/profil');
        self::assertResponseRedirects('/konto/anmelden');

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/konto/registrieren"]');
    }

    public function testHomePageUsesBoundedDisplaySettingsAndOnlyPublicTagsAndRecentVideos(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);
        $original = $settingsProvider->current();
        $originalOptions = [
            'homepage_featured_limit' => (string) $original->getHomepageFeaturedLimit(),
            'homepage_video_limit' => (string) $original->getHomepageVideoLimit(),
            'homepage_news_limit' => (string) $original->getHomepageNewsLimit(),
            'currently_watching_limit' => (string) $original->getCurrentlyWatchingLimit(),
            'public_statistics_enabled' => $original->isPublicStatisticsEnabled() ? '1' : '0',
            'tag_cloud_enabled' => $original->isTagCloudEnabled() ? '1' : '0',
            'tag_cloud_limit' => (string) $original->getTagCloudLimit(),
            'tag_cloud_shuffle' => $original->isTagCloudShuffleEnabled() ? '1' : '0',
        ];
        $suffix = bin2hex(random_bytes(5));
        $tag = new ContentTag('public-'.$suffix);
        $featuredOne = $this->featuredVideo('Display featured one '.$suffix, $tag);
        $featuredTwo = $this->featuredVideo('Display featured two '.$suffix, $tag);
        $latest = new Video(
            'Display latest '.$suffix,
            'A published item shown according to the home display limit.',
            'Display '.$suffix,
            VideoSourceType::Url,
            'https://videos.example.test/display-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $latest->replaceTags([$tag]);
        $draft = new Video(
            'Display draft '.$suffix,
            'A draft must not contribute to public tags or lists.',
            'Display '.$suffix,
            VideoSourceType::Url,
            'https://videos.example.test/display-draft-'.$suffix.'.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $draft->replaceTags([$tag]);
        $future = new Video(
            'Display future '.$suffix,
            'A future-dated video must not appear in public widgets or totals.',
            'Display '.$suffix,
            VideoSourceType::Url,
            'https://videos.example.test/display-future-'.$suffix.'.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $future->replaceTags([$tag]);

        try {
            $entityManager->persist($tag);
            $entityManager->persist($featuredOne);
            $entityManager->persist($featuredTwo);
            $entityManager->persist($latest);
            $entityManager->persist($draft);
            $entityManager->persist($future);
            $entityManager->flush();
            $entityManager->getConnection()->executeStatement(
                'UPDATE videos SET published_at = CURRENT_TIMESTAMP + CAST(:future_offset AS INTERVAL) WHERE id = :id',
                ['future_offset' => '1 day', 'id' => $future->getId()],
            );
            $expectedPublicVideos = $entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM videos WHERE status = :status AND published_at IS NOT NULL AND published_at <= CURRENT_TIMESTAMP',
                ['status' => PublishingStatus::Published->value],
            );
            if (!is_int($expectedPublicVideos) && !is_string($expectedPublicVideos)) {
                throw new \UnexpectedValueException('The public video count query returned a non-scalar value.');
            }
            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'homepage_featured_limit' => '2',
                'homepage_video_limit' => '1',
                'homepage_news_limit' => '0',
                'currently_watching_limit' => '2',
                'public_statistics_enabled' => '1',
                'tag_cloud_enabled' => '1',
                'tag_cloud_limit' => '10',
                'tag_cloud_shuffle' => '0',
            ]));

            $client->request('GET', '/videos/'.$latest->getId());
            self::assertResponseIsSuccessful();
            $client->request('GET', '/');

            self::assertResponseIsSuccessful();
            self::assertSame(1, $client->getCrawler()->filter('[data-testid="home-featured-video-section"] .content-card')->count());
            self::assertSelectorTextContains('main', 'Display latest '.$suffix);
            self::assertSelectorTextContains('[data-testid="home-left-rail"]', $tag->getName());
            self::assertSelectorExists('a[href="/videos?tag='.rawurlencode($tag->getName()).'"]');
            self::assertSelectorExists('#currently-watching-heading');
            self::assertSelectorTextContains('main', 'Display latest '.$suffix);
            self::assertSelectorExists('#home-public-statistics-heading');
            $statisticsValues = $client->getCrawler()->filter('.portal-statistics dd');
            self::assertCount(3, $statisticsValues);
            self::assertSame((string) $expectedPublicVideos, $statisticsValues->eq(0)->text());
            self::assertStringNotContainsString('Display draft '.$suffix, (string) $client->getResponse()->getContent());
            self::assertStringNotContainsString('Display future '.$suffix, (string) $client->getResponse()->getContent());

            $tagRepository = $client->getContainer()->get(ContentTagRepository::class);
            self::assertInstanceOf(ContentTagRepository::class, $tagRepository);
            $publicCloud = $tagRepository->findPublicVideoCloud(10);
            $publicTag = array_values(array_filter($publicCloud, static fn (array $item): bool => $item['name'] === $tag->getName()));
            self::assertCount(1, $publicTag);
            self::assertSame(3, $publicTag[0]['usage_count']);
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id IN (:one, :two, :latest, :draft, :future)', [
                'one' => $featuredOne->getId(),
                'two' => $featuredTwo->getId(),
                'latest' => $latest->getId(),
                'draft' => $draft->getId(),
                'future' => $future->getId(),
            ]);
            $entityManager->getConnection()->executeStatement('DELETE FROM content_tags WHERE id = :id', ['id' => $tag->getId()]);
            self::assertSame([], $settingsManager->updatePublicDisplayOptions($originalOptions));
        }
    }

    public function testPopularHomeNewsIsRankedPublicOnlyAndDisabledWithTheNewsListing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $settingsProvider = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $settingsProvider);

        $settings = $settingsProvider->current();
        $originalNewsLimit = $settings->getHomepageNewsLimit();
        $originalFeatureModules = [];
        foreach (FeatureModule::cases() as $module) {
            $originalFeatureModules[$module->inputName()] = $settings->isFeatureModuleEnabled($module) ? '1' : '0';
        }

        $suffix = bin2hex(random_bytes(6));
        $makeArticle = static function (
            string $key,
            PublishingStatus $status = PublishingStatus::Published,
            bool $featured = false,
            bool $membersOnly = false,
        ) use ($suffix): NewsArticle {
            return new NewsArticle(
                $key.' homepage article '.$suffix,
                $key.'-homepage-'.$suffix,
                'News',
                'Homepage article used to verify public popularity ordering.',
                'Article body '.$key,
                $status,
                $featured,
                true,
                'Homepage Desk',
                membersOnly: $membersOnly,
            );
        };

        $featuredLead = $makeArticle('featured-lead', featured: true);
        $popularOne = $makeArticle('most-viewed');
        $popularTwo = $makeArticle('second-most-viewed');
        $popularThree = $makeArticle('third-most-viewed');
        $popularFour = $makeArticle('fourth-most-viewed');
        $membersOnly = $makeArticle('private-high-count', membersOnly: true);
        $draft = $makeArticle('draft-high-count', PublishingStatus::Draft);
        $future = $makeArticle('future-high-count');
        $membersOnly->attachThumbnail(hash('sha256', 'members-only-news-'.$suffix), 'image/webp', 256);

        $featuredLead->setPublicationAt(new \DateTimeImmutable('-1 second', new \DateTimeZone('UTC')));
        foreach ([$popularOne, $popularTwo, $popularThree, $popularFour, $membersOnly, $future] as $article) {
            $article->setPublicationAt(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')));
        }
        $future->setPublicationAt(new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')));
        $articles = [$featuredLead, $popularOne, $popularTwo, $popularThree, $popularFour, $membersOnly, $draft, $future];

        $enabledFeatureModules = $originalFeatureModules;
        $enabledFeatureModules[FeatureModule::News->inputName()] = '1';

        try {
            self::assertSame([], $settingsManager->updateFeatureModules($enabledFeatureModules));
            foreach ($articles as $article) {
                $entityManager->persist($article);
            }
            $entityManager->flush();
            $connection = $entityManager->getConnection();
            $connection->executeStatement(
                'UPDATE news_articles SET published_at = CURRENT_TIMESTAMP + CAST(:future_offset AS INTERVAL) WHERE id = :id',
                ['future_offset' => '1 day', 'id' => $future->getId()],
            );
            foreach ([[$featuredLead, 100], [$popularOne, 90], [$popularTwo, 80], [$popularThree, 70], [$popularFour, 60], [$membersOnly, 2147483647], [$draft, 2147483647], [$future, 2147483647]] as [$article, $viewCount]) {
                $connection->executeStatement(
                    'UPDATE news_articles SET view_count = :view_count WHERE id = :id',
                    ['view_count' => $viewCount, 'id' => $article->getId()],
                );
            }
            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'homepage_news_limit' => '5',
            ]));

            $client->request('GET', '/');
            self::assertResponseIsSuccessful();

            $popularRail = $client->getCrawler()->filter('[data-testid="home-popular-news"]');
            self::assertSame(1, $popularRail->count());
            $popularLinks = $popularRail->filter('article.portal-mini a');
            self::assertSame(4, $popularLinks->count());
            self::assertSame('/news/'.$popularOne->getSlug(), $popularLinks->eq(0)->attr('href'));
            self::assertSame('/news/'.$popularTwo->getSlug(), $popularLinks->eq(1)->attr('href'));
            self::assertSame('/news/'.$popularThree->getSlug(), $popularLinks->eq(2)->attr('href'));
            self::assertSame('/news/'.$popularFour->getSlug(), $popularLinks->eq(3)->attr('href'));
            self::assertStringNotContainsString($featuredLead->getSlug(), $popularRail->html());

            $html = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($membersOnly->getSlug(), $popularRail->html());
            self::assertStringNotContainsString('/news/'.$membersOnly->getId().'/thumbnail', $html);
            self::assertStringNotContainsString($draft->getSlug(), $html);
            self::assertStringNotContainsString($future->getSlug(), $html);

            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'homepage_news_limit' => '0',
            ]));
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('[data-testid="home-popular-news"]');

            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'homepage_news_limit' => '5',
            ]));
            $disabledFeatureModules = $enabledFeatureModules;
            $disabledFeatureModules[FeatureModule::News->inputName()] = '0';
            self::assertSame([], $settingsManager->updateFeatureModules($disabledFeatureModules));
            $client->request('GET', '/');
            self::assertResponseIsSuccessful();
            self::assertSelectorNotExists('[data-testid="home-popular-news"]');
        } finally {
            foreach ($articles as $article) {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM news_articles WHERE id = :id',
                    ['id' => $article->getId()],
                );
            }
            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'homepage_news_limit' => (string) $originalNewsLimit,
            ]));
            self::assertSame([], $settingsManager->updateFeatureModules($originalFeatureModules));
        }
    }

    private function featuredVideo(string $title, ContentTag $tag): Video
    {
        $video = new Video(
            $title,
            'A published featured video for the configurable home page.',
            'Featured',
            VideoSourceType::Url,
            'https://videos.example.test/'.bin2hex(random_bytes(8)).'.mp4',
            PublishingStatus::Published,
            true,
            true,
        );
        $video->replaceTags([$tag]);

        return $video;
    }
}
