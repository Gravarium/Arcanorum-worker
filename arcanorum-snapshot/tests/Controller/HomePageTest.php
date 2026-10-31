<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContentTag;
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
        self::assertSelectorTextContains('h1', 'Arcanorum');
        self::assertSelectorTextContains('main', 'Neueste Videos');
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
            self::assertSelectorExists('nav.public-navigation a[href="/konto/anmelden"]');
            self::assertSelectorExists('nav.public-navigation a[href="/konto/registrieren"]');
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
            self::assertSelectorTextContains('main', $tag->getName());
            self::assertSelectorExists('a[href="/videos?tag='.rawurlencode($tag->getName()).'"]');
            self::assertSelectorExists('#currently-watching-heading');
            self::assertSelectorTextContains('main', 'Display latest '.$suffix);
            self::assertSelectorExists('#public-statistics-heading');
            $statisticsValues = $client->getCrawler()->filter('.public-statistics dd');
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
