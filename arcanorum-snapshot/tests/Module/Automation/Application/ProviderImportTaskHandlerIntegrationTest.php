<?php

declare(strict_types=1);

namespace App\Tests\Module\Automation\Application;

use App\Entity\ContentCategory;
use App\Entity\ContentCategoryType;
use App\Entity\PublishingStatus;
use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskType;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Automation\Application\ProviderImportTaskHandler;
use App\Module\Automation\Application\ScheduledTaskConfigurationValidator;
use App\Repository\ContentCategoryRepository;
use App\Repository\VideoRepository;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\VideoProviderImportService;
use App\Service\VideoSourceUrlLock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProviderImportTaskHandlerIntegrationTest extends KernelTestCase
{
    private const string API_KEY = 'scheduled-import-test-api-key';

    public function testScheduledImportFiltersItemsAndCreatesOnlyDeduplicatedDraftsInItsTargetCategory(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $entityManager = $container->get(EntityManagerInterface::class);
        $videos = $container->get(VideoRepository::class);
        $categories = $container->get(ContentCategoryRepository::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        self::assertInstanceOf(VideoRepository::class, $videos);
        self::assertInstanceOf(ContentCategoryRepository::class, $categories);

        $suffix = bin2hex(random_bytes(6));
        $category = new ContentCategory('Scheduled imports '.$suffix, ContentCategoryType::Video);
        $existingUrl = 'https://www.youtube.com/watch?v=existing123';
        $createdUrl = 'https://www.youtube.com/watch?v=abcdefghijk';
        $excludedUrl = 'https://www.youtube.com/watch?v=spoil123456';
        $existing = new Video(
            'Existing scheduled video '.$suffix,
            'Already present before the scheduled import.',
            $category->getName(),
            VideoSourceType::Embed,
            $existingUrl,
            PublishingStatus::Draft,
            false,
            true,
        );

        try {
            $entityManager->persist($category);
            $entityManager->persist($existing);
            $entityManager->flush();

            $provider = new VideoProviderImportService(
                new MockHttpClient([new MockResponse(json_encode([
                    'items' => [
                        [
                            'id' => ['videoId' => 'abcdefghijk'],
                            'snippet' => ['title' => 'New scheduled video', 'description' => 'A permitted result.'],
                        ],
                        [
                            'id' => ['videoId' => 'spoil123456'],
                            'snippet' => ['title' => 'A spoiler to exclude', 'description' => 'Excluded by policy.'],
                        ],
                        [
                            'id' => ['videoId' => 'existing123'],
                            'snippet' => ['title' => 'Duplicate scheduled video', 'description' => 'Already present.'],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR), ['http_code' => 200])]),
                new ExternalVideoEmbedResolver(),
                self::API_KEY,
            );
            $handler = new ProviderImportTaskHandler(
                new ScheduledTaskConfigurationValidator(),
                $provider,
                new VideoSourceUrlLock(),
                $videos,
                $categories,
                $entityManager,
            );
            $now = new \DateTimeImmutable('2026-10-29T12:00:00+00:00');
            $task = ScheduledTask::create(ScheduledTaskType::ProviderImport, 'Scheduled import '.$suffix, 60, [
                'provider' => 'youtube',
                'mode' => 'search',
                'query' => 'gaming',
                'channel_id' => '',
                'category' => $category->getName(),
                'exclude_terms' => ['spoiler'],
            ], $now);

            $summary = $handler->execute($task, $now);

            self::assertSame('1 Entwürfe angelegt, 1 Duplikate übersprungen, 1 wegen Ausschlussbegriffen verworfen.', $summary);
            $created = $videos->findBySourceUrl($createdUrl);
            self::assertInstanceOf(Video::class, $created);
            self::assertSame(PublishingStatus::Draft, $created->getStatus());
            self::assertSame($category->getName(), $created->getCategory());
            self::assertNull($videos->findBySourceUrl($excludedUrl));
            self::assertSame($existing->getId(), $videos->findBySourceUrl($existingUrl)?->getId());
        } finally {
            $connection = $entityManager->getConnection();
            $connection->executeStatement(
                'DELETE FROM videos WHERE source_url = :created_url OR source_url = :existing_url OR source_url = :excluded_url',
                ['created_url' => $createdUrl, 'existing_url' => $existingUrl, 'excluded_url' => $excludedUrl],
            );
            $connection->executeStatement('DELETE FROM content_categories WHERE id = :id', ['id' => $category->getId()]);
        }
    }
}
