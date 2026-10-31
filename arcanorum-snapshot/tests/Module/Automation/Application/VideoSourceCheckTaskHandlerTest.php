<?php

declare(strict_types=1);

namespace App\Tests\Module\Automation\Application;

use App\Entity\ExternalVideoSourceStatus;
use App\Entity\PublishingStatus;
use App\Entity\ScheduledTask;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Automation\Application\ExternalVideoSourceStore;
use App\Module\Automation\Application\VideoSourceCheckTaskHandler;
use App\Service\ExternalVideoEmbedResolver;
use App\Service\VideoProviderImportException;
use App\Service\VideoProviderImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class VideoSourceCheckTaskHandlerTest extends TestCase
{
    public function testUnavailableProviderSourceIsReportedWithoutChangingPublicationOrDeletingVideo(): void
    {
        $now = new \DateTimeImmutable('2026-10-29T12:00:00+00:00');
        $video = new Video(
            'Unverfügbare Providerquelle',
            'Die Quelle bleibt zur Moderation erhalten.',
            null,
            VideoSourceType::Embed,
            'https://youtu.be/dQw4w9WgXcQ',
            PublishingStatus::Published,
            false,
            true,
        );
        $video->markExternalSourceStatus(ExternalVideoSourceStatus::Available, $now->modify('-48 hours'));

        $videos = $this->createMock(ExternalVideoSourceStore::class);
        $videos->expects(self::once())
            ->method('findExternalSourcesNeedingCheck')
            ->with($now->modify('-24 hours'), 5)
            ->willReturn([$video]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::never())->method('remove');
        $provider = new VideoProviderImportService(
            new MockHttpClient([new MockResponse('{"items":[]}', ['http_code' => 200])]),
            new ExternalVideoEmbedResolver(),
            'source-check-test-api-key',
        );
        $handler = new VideoSourceCheckTaskHandler($provider, $videos, $entityManager);

        $summary = $handler->execute(ScheduledTask::createSystemSourceCheck($now->modify('-2 hours')), $now);

        self::assertSame(ExternalVideoSourceStatus::Unavailable, $video->getExternalSourceStatus());
        self::assertSame($now, $video->getExternalSourceCheckedAt());
        self::assertSame(PublishingStatus::Published, $video->getStatus());
        self::assertStringContainsString('zur Moderation vorgemerkt', $summary);
    }

    public function testProviderFailureAdvancesRetryCursorAndPreservesLastKnownStatus(): void
    {
        $now = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $lastCheckedAt = $now->modify('-48 hours');
        $video = new Video(
            'Provider retry regression',
            'A temporary API failure must not block other sources.',
            null,
            VideoSourceType::Embed,
            'https://youtu.be/dQw4w9WgXcQ',
            PublishingStatus::Draft,
            false,
            true,
        );
        $video->markExternalSourceStatus(ExternalVideoSourceStatus::Available, $lastCheckedAt);

        $videos = $this->createMock(ExternalVideoSourceStore::class);
        $videos->expects(self::once())
            ->method('findExternalSourcesNeedingCheck')
            ->with($now->modify('-24 hours'), 5)
            ->willReturn([$video]);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $provider = new VideoProviderImportService(
            new MockHttpClient([new MockResponse('{"error":"quota"}', ['http_code' => 429])]),
            new ExternalVideoEmbedResolver(),
            'source-check-test-api-key',
        );
        $handler = new VideoSourceCheckTaskHandler($provider, $videos, $entityManager);

        try {
            $handler->execute(ScheduledTask::createSystemSourceCheck($now->modify('-2 hours')), $now);
            self::fail('The failed provider response must fail the task without marking the source unavailable.');
        } catch (VideoProviderImportException $exception) {
            self::assertSame('Mindestens eine Providerprüfung konnte nicht abgeschlossen werden.', $exception->getMessage());
        }

        self::assertSame(ExternalVideoSourceStatus::Available, $video->getExternalSourceStatus());
        self::assertSame($now, $video->getExternalSourceCheckedAt());
        self::assertTrue($video->isExternalSourceCheckFailed());
    }
}
