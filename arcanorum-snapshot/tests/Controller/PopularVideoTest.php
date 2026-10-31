<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PublishingStatus;
use App\Entity\Video;
use App\Entity\VideoPopularitySort;
use App\Entity\VideoSourceType;
use App\Service\SiteSettingsManager;
use App\Service\SiteSettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PopularVideoTest extends WebTestCase
{
    public function testAutomaticFeatureSettingAppliesOnlyAtTheExactCountedViewThreshold(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $siteSettings = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $siteSettings);
        $originalThreshold = $siteSettings->current()->getAutoFeatureViewThreshold();
        $suffix = bin2hex(random_bytes(6));
        $crossing = new Video(
            'Threshold crossing '.$suffix,
            'The highlight changes only when the configured counted view is reached.',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-crossing.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $throughPage = new Video(
            'Threshold through public page '.$suffix,
            'The public request passes the current site threshold to the atomic view update.',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-public-page.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $disabled = new Video(
            'Disabled automatic highlight '.$suffix,
            'A zero threshold leaves automatic highlighting disabled.',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-disabled.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $draft = new Video(
            'Draft threshold '.$suffix,
            'Unpublished videos must not gain public views or automatic highlights.',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-draft.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $future = new Video(
            'Scheduled threshold '.$suffix,
            'Scheduled videos must not gain public views or automatic highlights.',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-future.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $repository = $client->getContainer()->get(\App\Repository\VideoRepository::class);
        self::assertInstanceOf(\App\Repository\VideoRepository::class, $repository);

        try {
            self::assertSame([], $settingsManager->updateContentOptions(['auto_feature_view_threshold' => '2']));
            $entityManager->persist($crossing);
            $entityManager->persist($throughPage);
            $entityManager->persist($disabled);
            $entityManager->persist($draft);
            $entityManager->persist($future);
            $entityManager->flush();

            $repository->recordPublicView($disabled->getId(), 0);
            $connection = $entityManager->getConnection();
            self::assertSame('1', $connection->fetchOne('SELECT view_count::text FROM videos WHERE id = :id', ['id' => $disabled->getId()]));
            self::assertSame('false', $connection->fetchOne('SELECT featured::text FROM videos WHERE id = :id', ['id' => $disabled->getId()]));

            $repository->recordPublicView($crossing->getId(), 2);
            self::assertSame('1', $connection->fetchOne('SELECT view_count::text FROM videos WHERE id = :id', ['id' => $crossing->getId()]));
            self::assertSame('false', $connection->fetchOne('SELECT featured::text FROM videos WHERE id = :id', ['id' => $crossing->getId()]));

            $repository->recordPublicView($crossing->getId(), 2);
            self::assertSame('2', $connection->fetchOne('SELECT view_count::text FROM videos WHERE id = :id', ['id' => $crossing->getId()]));
            self::assertSame('true', $connection->fetchOne('SELECT featured::text FROM videos WHERE id = :id', ['id' => $crossing->getId()]));

            $connection->executeStatement('UPDATE videos SET featured = FALSE WHERE id = :id', ['id' => $crossing->getId()]);
            $repository->recordPublicView($crossing->getId(), 2);
            self::assertSame('false', $connection->fetchOne('SELECT featured::text FROM videos WHERE id = :id', ['id' => $crossing->getId()]));

            self::assertSame([], $settingsManager->updateContentOptions(['auto_feature_view_threshold' => '1']));
            $client->request('GET', '/videos/'.$throughPage->getId());
            self::assertResponseIsSuccessful();
            self::assertSame('1', $connection->fetchOne('SELECT view_count::text FROM videos WHERE id = :id', ['id' => $throughPage->getId()]));
            self::assertSame('true', $connection->fetchOne('SELECT featured::text FROM videos WHERE id = :id', ['id' => $throughPage->getId()]));
            $client->request('GET', '/videos/'.$throughPage->getId());
            self::assertResponseIsSuccessful();
            self::assertSame('1', $connection->fetchOne('SELECT view_count::text FROM videos WHERE id = :id', ['id' => $throughPage->getId()]));
            $connection->executeStatement('UPDATE videos SET featured = FALSE WHERE id = :id', ['id' => $throughPage->getId()]);
            $client->request('GET', '/videos/'.$throughPage->getId());
            self::assertResponseIsSuccessful();
            self::assertSame('false', $connection->fetchOne('SELECT featured::text FROM videos WHERE id = :id', ['id' => $throughPage->getId()]));

            $repository->recordPublicView($draft->getId(), 1);
            self::assertSame('0', $connection->fetchOne('SELECT view_count::text FROM videos WHERE id = :id', ['id' => $draft->getId()]));
            self::assertSame('false', $connection->fetchOne('SELECT featured::text FROM videos WHERE id = :id', ['id' => $draft->getId()]));

            $connection->executeStatement(
                'UPDATE videos SET published_at = CURRENT_TIMESTAMP + INTERVAL \'1 day\' WHERE id = :id',
                ['id' => $future->getId()],
            );
            $repository->recordPublicView($future->getId(), 1);
            self::assertSame('0', $connection->fetchOne('SELECT view_count::text FROM videos WHERE id = :id', ['id' => $future->getId()]));
            self::assertSame('false', $connection->fetchOne('SELECT featured::text FROM videos WHERE id = :id', ['id' => $future->getId()]));
        } finally {
            try {
                self::assertSame([], $settingsManager->updateContentOptions([
                    'auto_feature_view_threshold' => (string) $originalThreshold,
                ]));
            } finally {
                $entityManager->getConnection()->executeStatement(
                    'DELETE FROM videos WHERE id IN (:crossing, :through_page, :disabled, :draft, :future)',
                    [
                        'crossing' => $crossing->getId(),
                        'through_page' => $throughPage->getId(),
                        'disabled' => $disabled->getId(),
                        'draft' => $draft->getId(),
                        'future' => $future->getId(),
                    ],
                );
            }
        }
    }

    public function testPublishedViewsAreCountedOncePerSessionAndSortedWithoutDrafts(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $siteSettings = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $siteSettings);
        $originalSort = $siteSettings->current()->getPopularVideoSort();
        $suffix = bin2hex(random_bytes(6));
        $popular = new Video(
            'Popular '.$suffix,
            'Aufrufzähler',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-popular.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $unseen = new Video(
            'Unseen '.$suffix,
            'Noch keine Aufrufe',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-unseen.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $draft = new Video(
            'Draft '.$suffix,
            'Nicht öffentlich',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-draft.mp4',
            PublishingStatus::Draft,
            false,
            true,
        );
        $future = new Video(
            'Future '.$suffix,
            'A publication scheduled for a future date must not be public yet.',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-future.mp4',
            PublishingStatus::Published,
            false,
            true,
        );

        try {
            $entityManager->persist($popular);
            $entityManager->persist($unseen);
            $entityManager->persist($draft);
            $entityManager->persist($future);
            $entityManager->flush();
            $entityManager->getConnection()->executeStatement(
                'UPDATE videos SET published_at = CURRENT_TIMESTAMP + INTERVAL \'1 day\' WHERE id = :id',
                ['id' => $future->getId()],
            );

            $client->request('GET', '/videos/'.$draft->getId());
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/videos/'.$future->getId());
            self::assertResponseStatusCodeSame(404);
            $client->request('GET', '/videos/'.$popular->getId());
            self::assertResponseIsSuccessful();
            $cacheControl = (string) $client->getResponse()->headers->get('Cache-Control');
            self::assertStringContainsString('private', $cacheControl);
            self::assertStringContainsString('no-store', $cacheControl);
            $client->request('GET', '/videos/'.$popular->getId());
            self::assertResponseIsSuccessful();

            $connection = $entityManager->getConnection();
            $popularCount = $connection->fetchOne('SELECT view_count FROM videos WHERE id = :id', ['id' => $popular->getId()]);
            $draftCount = $connection->fetchOne('SELECT view_count FROM videos WHERE id = :id', ['id' => $draft->getId()]);
            $dailyCount = $connection->fetchOne('SELECT view_count FROM video_daily_views WHERE video_id = :id', ['id' => $popular->getId()]);
            $lastViewedAt = $connection->fetchOne('SELECT last_viewed_at FROM videos WHERE id = :id', ['id' => $popular->getId()]);
            self::assertTrue(1 === $popularCount || '1' === $popularCount);
            self::assertTrue(0 === $draftCount || '0' === $draftCount);
            self::assertTrue(1 === $dailyCount || '1' === $dailyCount);
            self::assertNotFalse($lastViewedAt);

            $client->request('GET', '/videos/popular');
            self::assertResponseIsSuccessful();
            self::assertNotNull($client->getResponse()->headers->get('Content-Security-Policy-Report-Only'));
            self::assertSelectorTextContains('h1', 'Beliebte Videos');
            $html = (string) $client->getResponse()->getContent();
            self::assertMatchesRegularExpression('/Popular '.$suffix.'.*Unseen '.$suffix.'/s', $html);
            self::assertStringNotContainsString('Draft '.$suffix, $html);
            self::assertStringNotContainsString('Future '.$suffix, $html);

            $connection->executeStatement(
                'INSERT INTO video_daily_views (video_id, view_date, view_count) VALUES (:id, CURRENT_DATE, 20)',
                ['id' => $unseen->getId()],
            );
            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['popular_video_sort' => VideoPopularitySort::RecentViews->value]));
            $client->request('GET', '/videos/popular');
            self::assertResponseIsSuccessful();
            self::assertMatchesRegularExpression('/Unseen '.$suffix.'.*Popular '.$suffix.'/s', (string) $client->getResponse()->getContent());
        } finally {
            self::assertSame([], $settingsManager->updatePublicDisplayOptions(['popular_video_sort' => $originalSort->value]));
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM videos WHERE id IN (:popular, :unseen, :draft, :future)',
                ['popular' => $popular->getId(), 'unseen' => $unseen->getId(), 'draft' => $draft->getId(), 'future' => $future->getId()],
            );
        }
    }

    public function testRecentViewsUseTheConfiguredPeriod(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        $settingsManager = $client->getContainer()->get(SiteSettingsManager::class);
        $settings = $client->getContainer()->get(SiteSettingsProvider::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(SiteSettingsManager::class, $settingsManager);
        self::assertInstanceOf(SiteSettingsProvider::class, $settings);
        $originalSort = $settings->current()->getPopularVideoSort()->value;
        $originalPeriod = (string) $settings->current()->getPopularVideoPeriodDays();
        $suffix = bin2hex(random_bytes(6));
        $recent = new Video(
            'Recent window '.$suffix,
            'A view inside the configured popularity window.',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-recent-window.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $older = new Video(
            'Older window '.$suffix,
            'Views outside the short popularity window must not rank as recent.',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-older-window.mp4',
            PublishingStatus::Published,
            false,
            true,
        );

        try {
            $entityManager->persist($recent);
            $entityManager->persist($older);
            $entityManager->flush();
            $connection = $entityManager->getConnection();
            $connection->executeStatement(
                'INSERT INTO video_daily_views (video_id, view_date, view_count) VALUES (:id, CURRENT_DATE, 5)',
                ['id' => $recent->getId()],
            );
            $connection->executeStatement(
                'INSERT INTO video_daily_views (video_id, view_date, view_count) VALUES (:id, CURRENT_DATE - 10, 100)',
                ['id' => $older->getId()],
            );

            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'popular_video_sort' => VideoPopularitySort::RecentViews->value,
                'popular_video_period_days' => '7',
            ]));
            $client->request('GET', '/videos/popular');
            self::assertResponseIsSuccessful();
            self::assertMatchesRegularExpression(
                '/Recent window '.$suffix.'.*Older window '.$suffix.'/s',
                (string) $client->getResponse()->getContent(),
            );

            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'popular_video_period_days' => '30',
            ]));
            $client->request('GET', '/videos/popular');
            self::assertResponseIsSuccessful();
            self::assertMatchesRegularExpression(
                '/Older window '.$suffix.'.*Recent window '.$suffix.'/s',
                (string) $client->getResponse()->getContent(),
            );
        } finally {
            self::assertSame([], $settingsManager->updatePublicDisplayOptions([
                'popular_video_sort' => $originalSort,
                'popular_video_period_days' => $originalPeriod,
            ]));
            $entityManager->getConnection()->executeStatement(
                'DELETE FROM videos WHERE id IN (:recent, :older)',
                ['recent' => $recent->getId(), 'older' => $older->getId()],
            );
        }
    }

    public function testViewCountersSaturateAtIntegerMaximum(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $entityManager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $suffix = bin2hex(random_bytes(6));
        $video = new Video(
            'Saturated counters '.$suffix,
            'Public view counters must remain within the PostgreSQL integer range.',
            null,
            VideoSourceType::Url,
            'https://videos.example.test/'.$suffix.'-saturated.mp4',
            PublishingStatus::Published,
            false,
            true,
        );
        $repository = $client->getContainer()->get(\App\Repository\VideoRepository::class);
        self::assertInstanceOf(\App\Repository\VideoRepository::class, $repository);

        try {
            $entityManager->persist($video);
            $entityManager->flush();
            $connection = $entityManager->getConnection();
            $connection->executeStatement('UPDATE videos SET view_count = 2147483647 WHERE id = :id', ['id' => $video->getId()]);
            $connection->executeStatement(
                'INSERT INTO video_daily_views (video_id, view_date, view_count) VALUES (:id, CURRENT_DATE, 2147483647)',
                ['id' => $video->getId()],
            );

            $repository->recordPublicView($video->getId());

            $viewCount = $connection->fetchOne('SELECT view_count FROM videos WHERE id = :id', ['id' => $video->getId()]);
            if (!is_int($viewCount) && !is_string($viewCount)) {
                throw new \UnexpectedValueException('The video view count query returned a non-scalar value.');
            }
            self::assertSame(2147483647, (int) $viewCount);

            $dailyViewCount = $connection->fetchOne('SELECT view_count FROM video_daily_views WHERE video_id = :id AND view_date = CURRENT_DATE', ['id' => $video->getId()]);
            if (!is_int($dailyViewCount) && !is_string($dailyViewCount)) {
                throw new \UnexpectedValueException('The daily video view count query returned a non-scalar value.');
            }
            self::assertSame(2147483647, (int) $dailyViewCount);
            self::assertNotFalse($connection->fetchOne('SELECT last_viewed_at FROM videos WHERE id = :id', ['id' => $video->getId()]));
        } finally {
            $entityManager->getConnection()->executeStatement('DELETE FROM videos WHERE id = :id', ['id' => $video->getId()]);
        }
    }
}
