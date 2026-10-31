<?php

declare(strict_types=1);

namespace App\Tests\Migration;

use App\Entity\ExternalVideoSourceStatus;
use App\Entity\PublishingStatus;
use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskRun;
use App\Entity\ScheduledTaskType;
use App\Entity\Video;
use App\Entity\VideoSourceType;
use App\Module\Feed\Service\SitemapCacheRevisionProvider;
use App\Repository\VideoRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261028223107;
use DoctrineMigrations\Version20261029170500;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ScheduledAutomationMigrationTest extends KernelTestCase
{
    private const string ROLLBACK_GUARD_FAILURE = 'Automatisierungsaufträge, der feste Videoquellenauftrag, geprüfte Providerzustände, Laufprotokolle und noch gültige Scheduler-Nonces dürfen durch den Migrationsrückbau nicht verloren gehen.';

    public function testRelevantVideoChangesAdvanceSitemapRevisionButViewCountersDoNot(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $video = new Video(
            'Automation revision '.$this->suffix(),
            'Published video used to verify the sitemap revision trigger.',
            null,
            VideoSourceType::Embed,
            'https://youtu.be/dQw4w9WgXcQ',
            PublishingStatus::Published,
            false,
            false,
        );

        try {
            $entityManager->persist($video);
            $entityManager->flush();
            $revisions = static::getContainer()->get(SitemapCacheRevisionProvider::class);
            self::assertInstanceOf(SitemapCacheRevisionProvider::class, $revisions);
            $afterInsert = $revisions->current();

            $videos = static::getContainer()->get(VideoRepository::class);
            self::assertInstanceOf(VideoRepository::class, $videos);
            $videos->recordPublicView($video->getId());
            self::assertSame($afterInsert, $revisions->current());

            $video->revise(
                'Updated automation revision '.$this->suffix(),
                $video->getDescription(),
                null,
                VideoSourceType::Embed,
                $video->getSourceUrl(),
                PublishingStatus::Published,
                false,
                false,
            );
            $entityManager->flush();

            self::assertNotSame($afterInsert, $revisions->current());
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testTaskDeletionKeepsRunHistoryAndDowngradeGuardRefusesDataLoss(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $task = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Automation migration test', 60, [], $now);
        $run = new ScheduledTaskRun($task, $task->getNextRunAt(), $now, 'migration-test');
        $leaseToken = $run->takeLeaseToken();

        try {
            $entityManager->persist($task);
            $entityManager->persist($run);
            $entityManager->flush();

            $persistedRun = $connection->fetchAssociative(
                'SELECT attempt_count, last_attempt_at, lease_token_digest, lease_expires_at, retry_after, version FROM scheduled_task_runs WHERE id = :id',
                ['id' => $run->getId()],
            );
            self::assertIsArray($persistedRun);
            self::assertContains($persistedRun['attempt_count'], [1, '1']);
            self::assertSame(hash('sha256', $leaseToken), $persistedRun['lease_token_digest']);
            self::assertNotSame($leaseToken, $persistedRun['lease_token_digest']);
            self::assertNotNull($persistedRun['lease_expires_at']);
            self::assertNull($persistedRun['retry_after']);
            self::assertContains($persistedRun['version'], [1, '1']);

            self::assertSame($task->getId(), $connection->fetchOne(
                'SELECT task_id FROM scheduled_task_runs WHERE id = :id',
                ['id' => $run->getId()],
            ));

            $connection->executeStatement('DELETE FROM scheduled_tasks WHERE id = :id', ['id' => $task->getId()]);
            self::assertNull($connection->fetchOne(
                'SELECT task_id FROM scheduled_task_runs WHERE id = :id',
                ['id' => $run->getId()],
            ));
            $remainingRuns = $connection->fetchOne('SELECT COUNT(*) FROM scheduled_task_runs WHERE id = :id', ['id' => $run->getId()]);
            if (!is_int($remainingRuns) && !is_string($remainingRuns)) {
                self::fail('Die Anzahl der erhaltenen Aufgabenläufe ist ungültig.');
            }
            self::assertSame('1', (string) $remainingRuns);

            $migration = new Version20261028223107($connection, new NullLogger());
            try {
                $migration->down(new Schema());
                self::fail('A downgrade must preserve automation run history.');
            } catch (\RuntimeException $exception) {
                self::assertSame(self::ROLLBACK_GUARD_FAILURE, $exception->getMessage());
            }

            $retryMigration = new Version20261029170500($connection, new NullLogger());
            try {
                $retryMigration->down(new Schema());
                self::fail('A downgrade must preserve bounded retry and lease history.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Aufgabenlauf- und Wiederholungsnachweise dürfen durch den Migrationsrückbau nicht verloren gehen.', $exception->getMessage());
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testDowngradeGuardRefusesPausedFixedSystemTask(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            self::assertSame(1, $connection->executeStatement(
                "UPDATE scheduled_tasks SET enabled = FALSE WHERE task_key = 'video-source-health-check'",
            ));

            $this->assertFixedSystemTaskDowngradeRefused($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testDowngradeGuardRefusesRetimedFixedSystemTask(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();

        try {
            self::assertSame(1, $connection->executeStatement(
                "UPDATE scheduled_tasks
                 SET interval_minutes = 120, next_run_at = created_at + INTERVAL '120 minutes'
                 WHERE task_key = 'video-source-health-check'",
            ));

            $this->assertFixedSystemTaskDowngradeRefused($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    public function testDowngradeGuardRefusesStoredExternalSourceStatus(): void
    {
        self::bootKernel();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $connection = $entityManager->getConnection();
        $connection->beginTransaction();
        $video = new Video(
            'Automation rollback source status '.$this->suffix(),
            'Video fixture for migration rollback status preservation.',
            null,
            VideoSourceType::Embed,
            'https://youtu.be/dQw4w9WgXcQ',
            PublishingStatus::Draft,
            false,
            true,
        );
        $video->markExternalSourceStatus(
            ExternalVideoSourceStatus::Unavailable,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        try {
            $entityManager->persist($video);
            $entityManager->flush();

            $this->assertAutomationDowngradeRefused($connection);
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $entityManager->clear();
        }
    }

    private function assertFixedSystemTaskDowngradeRefused(Connection $connection): void
    {
        $this->assertAutomationDowngradeRefused($connection);
    }

    private function assertAutomationDowngradeRefused(Connection $connection): void
    {
        $migration = new Version20261028223107($connection, new NullLogger());
        try {
            $migration->down(new Schema());
            self::fail('A downgrade must preserve the fixed task configuration.');
        } catch (\RuntimeException $exception) {
            self::assertSame(
                self::ROLLBACK_GUARD_FAILURE,
                $exception->getMessage(),
            );
        }
    }

    private function suffix(): string
    {
        return bin2hex(random_bytes(6));
    }
}
