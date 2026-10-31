<?php

declare(strict_types=1);

namespace App\Tests\Module\Automation\Application;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskRun;
use App\Entity\ScheduledTaskRunStatus;
use App\Entity\ScheduledTaskType;
use App\Module\Automation\Application\ScheduledTaskHandlerInterface;
use App\Module\Automation\Application\ScheduledTaskHandlerRegistry;
use App\Module\Automation\Application\ScheduledTaskRunner;
use App\Module\Automation\Application\ScheduledTaskRunStore;
use App\Module\Automation\Application\ScheduledTaskStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ScheduledTaskRunnerTest extends TestCase
{
    public function testClaimsRunsAndRecordsOneDueTask(): void
    {
        [$task, $now] = self::dueTask();
        $handler = $this->createMock(ScheduledTaskHandlerInterface::class);
        $handler->method('type')->willReturn(ScheduledTaskType::SitemapRefresh);
        $handler->expects(self::once())
            ->method('execute')
            ->with(self::identicalTo($task), self::equalTo($now))
            ->willReturn('Both sitemaps were refreshed.');
        $connection = $this->connectionWithLock();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        /** @var list<ScheduledTaskRun> $persisted */
        $persisted = [];
        $entityManager->method('find')->willReturnCallback(static function (string $className, string $id, ?LockMode $lockMode = null) use (&$persisted, $task): ?object {
            if (ScheduledTask::class === $className) {
                return $task;
            }

            if (ScheduledTaskRun::class === $className) {
                return $persisted[0] ?? null;
            }

            return null;
        });
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::once())->method('clear');

        $tasks = $this->createMock(ScheduledTaskStore::class);
        $tasks->expects(self::once())->method('findDue')->with($now, 10)->willReturn([$task]);
        $tasks->expects(self::exactly(2))->method('findByTaskKey')->with($task->getTaskKey())->willReturn($task);
        $runs = $this->createMock(ScheduledTaskRunStore::class);
        $runs->expects(self::once())->method('findExistingForDueTime')->with($task, $task->getNextRunAt())->willReturn(null);
        $runs->expects(self::once())->method('pruneHistory')->with(200)->willReturn(0);

        $runner = new ScheduledTaskRunner(
            $connection,
            $entityManager,
            $tasks,
            $runs,
            new ScheduledTaskHandlerRegistry([$handler]),
            new MockClock($now),
        );

        self::assertSame(1, $runner->runDueTasks('scheduler-01', $now));
        self::assertCount(1, $persisted);
        self::assertInstanceOf(ScheduledTaskRun::class, $persisted[0]);
        self::assertSame(ScheduledTaskRunStatus::Succeeded, $persisted[0]->getStatus());
        self::assertSame('Both sitemaps were refreshed.', $persisted[0]->getResultSummary());
        self::assertSame(1, $persisted[0]->getAttemptCount());
        self::assertEquals($now->modify('+15 minutes'), $task->getNextRunAt());
    }

    public function testUnavailableAdvisoryLockDoesNotStartAnyTask(): void
    {
        [$task, $now] = self::dueTask();
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn('f');
        $connection->expects(self::never())->method('transactional');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('find');
        $tasks = $this->createMock(ScheduledTaskStore::class);
        $tasks->expects(self::never())->method('findDue');
        $runs = $this->createMock(ScheduledTaskRunStore::class);
        $runs->expects(self::never())->method('findExistingForDueTime');

        $runner = new ScheduledTaskRunner(
            $connection,
            $entityManager,
            $tasks,
            $runs,
            new ScheduledTaskHandlerRegistry([]),
            new MockClock($now),
        );

        self::assertSame(0, $runner->runDueTasks('scheduler-01', $now));
    }

    public function testActiveExistingLeaseIsNotExecutedTwice(): void
    {
        [$task, $now] = self::dueTask();
        $dueAt = $task->getNextRunAt();
        $existing = new ScheduledTaskRun($task, $task->getNextRunAt(), $now, 'scheduler-01');
        $connection = $this->connectionWithLock(1);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('find')->willReturn($task);
        $entityManager->expects(self::never())->method('persist');
        $tasks = $this->createMock(ScheduledTaskStore::class);
        $tasks->expects(self::once())->method('findDue')->willReturn([$task]);
        $tasks->expects(self::never())->method('findByTaskKey');
        $runs = $this->createMock(ScheduledTaskRunStore::class);
        $runs->expects(self::once())->method('findExistingForDueTime')->willReturn($existing);
        $runs->expects(self::once())->method('pruneHistory')->willReturn(0);
        $handler = $this->createMock(ScheduledTaskHandlerInterface::class);
        $handler->method('type')->willReturn(ScheduledTaskType::SitemapRefresh);
        $handler->expects(self::never())->method('execute');

        $runner = new ScheduledTaskRunner(
            $connection,
            $entityManager,
            $tasks,
            $runs,
            new ScheduledTaskHandlerRegistry([$handler]),
            new MockClock($now),
        );

        self::assertSame(0, $runner->runDueTasks('scheduler-01', $now));
        self::assertSame($dueAt, $task->getNextRunAt());
    }

    public function testHandlerFailureStoresOnlyGenericResultAndKeepsOccurrenceDueForRetry(): void
    {
        [$task, $now] = self::dueTask();
        $dueAt = $task->getNextRunAt();
        $handler = $this->createMock(ScheduledTaskHandlerInterface::class);
        $handler->method('type')->willReturn(ScheduledTaskType::SitemapRefresh);
        $handler->expects(self::once())->method('execute')->willThrowException(new \RuntimeException('provider-secret-response'));
        $connection = $this->connectionWithLock();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        /** @var list<ScheduledTaskRun> $persisted */
        $persisted = [];
        $entityManager->method('find')->willReturnCallback(static function (string $className) use (&$persisted, $task): ?object {
            return ScheduledTask::class === $className ? $task : ($persisted[0] ?? null);
        });
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::once())->method('clear');
        $tasks = $this->createMock(ScheduledTaskStore::class);
        $tasks->expects(self::once())->method('findDue')->willReturn([$task]);
        $tasks->expects(self::once())->method('findByTaskKey')->willReturn($task);
        $runs = $this->createMock(ScheduledTaskRunStore::class);
        $runs->expects(self::once())->method('findExistingForDueTime')->willReturn(null);
        $runs->expects(self::once())->method('pruneHistory')->willReturn(0);

        $runner = new ScheduledTaskRunner(
            $connection,
            $entityManager,
            $tasks,
            $runs,
            new ScheduledTaskHandlerRegistry([$handler]),
            new MockClock($now),
        );

        self::assertSame(1, $runner->runDueTasks('scheduler-01', $now));
        self::assertInstanceOf(ScheduledTaskRun::class, $persisted[0]);
        self::assertSame(ScheduledTaskRunStatus::Failed, $persisted[0]->getStatus());
        self::assertSame(1, $persisted[0]->getAttemptCount());
        self::assertStringNotContainsString('provider-secret-response', (string) $persisted[0]->getResultSummary());
        self::assertSame('Der Auftrag ist fehlgeschlagen. Prüfe die Server- und Anbieterkonfiguration.', $persisted[0]->getResultSummary());
        self::assertEquals($now->modify('+30 seconds'), $persisted[0]->getRetryAfter());
        self::assertSame($dueAt, $task->getNextRunAt());
    }

    public function testHandlerReturningAfterLeaseExpiryRemainsRecoverable(): void
    {
        [$task, $now] = self::dueTask();
        $clock = new MockClock($now);
        $handler = $this->createMock(ScheduledTaskHandlerInterface::class);
        $handler->method('type')->willReturn(ScheduledTaskType::SitemapRefresh);
        $handler->expects(self::once())->method('execute')->willReturnCallback(static function () use ($clock): string {
            $clock->sleep(121);

            return 'The late completion must be discarded.';
        });
        $connection = $this->connectionWithLock();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        /** @var list<ScheduledTaskRun> $persisted */
        $persisted = [];
        $entityManager->method('find')->willReturnCallback(static function (string $className) use (&$persisted, $task): ?object {
            return ScheduledTask::class === $className ? $task : ($persisted[0] ?? null);
        });
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::once())->method('clear');
        $tasks = $this->createMock(ScheduledTaskStore::class);
        $tasks->expects(self::once())->method('findDue')->willReturn([$task]);
        $tasks->expects(self::once())->method('findByTaskKey')->willReturn($task);
        $runs = $this->createMock(ScheduledTaskRunStore::class);
        $runs->expects(self::once())->method('findExistingForDueTime')->willReturn(null);
        $runs->expects(self::once())->method('pruneHistory')->willReturn(0);

        $runner = new ScheduledTaskRunner(
            $connection,
            $entityManager,
            $tasks,
            $runs,
            new ScheduledTaskHandlerRegistry([$handler]),
            $clock,
        );

        self::assertSame(0, $runner->runDueTasks('scheduler-01', $now));
        self::assertInstanceOf(ScheduledTaskRun::class, $persisted[0]);
        self::assertSame(ScheduledTaskRunStatus::Running, $persisted[0]->getStatus());
        self::assertNull($persisted[0]->getFinishedAt());
        self::assertSame(1, $persisted[0]->getAttemptCount());
        self::assertTrue($persisted[0]->isRetryAvailableAt($clock->now()));
        $idempotencyKey = $persisted[0]->getIdempotencyKey();
        self::assertTrue($persisted[0]->claimForRetry('scheduler-02', $clock->now()));
        self::assertSame(2, $persisted[0]->getAttemptCount());
        self::assertSame($idempotencyKey, $persisted[0]->getIdempotencyKey());
    }

    public function testRetryReusesTheSameRunAndStableIdempotencyKey(): void
    {
        [$task, $taskDueAt] = self::dueTask();
        $firstStartedAt = $taskDueAt;
        $existing = new ScheduledTaskRun($task, $taskDueAt, $firstStartedAt, 'scheduler-01');
        $existing->fail($existing->takeLeaseToken(), 'Temporary provider error.', $firstStartedAt);
        $retryAt = $existing->getRetryAfter();
        self::assertInstanceOf(\DateTimeImmutable::class, $retryAt);
        $idempotencyKey = $existing->getIdempotencyKey();
        $now = $retryAt->modify('+1 second');
        $handler = $this->createMock(ScheduledTaskHandlerInterface::class);
        $handler->method('type')->willReturn(ScheduledTaskType::SitemapRefresh);
        $handler->expects(self::once())->method('execute')->with(self::identicalTo($task), self::equalTo($now))->willReturn('Sitemaps refreshed.');
        $connection = $this->connectionWithLock();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(static fn (string $className): object => ScheduledTask::class === $className ? $task : $existing);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::once())->method('clear');
        $tasks = $this->createMock(ScheduledTaskStore::class);
        $tasks->expects(self::once())->method('findDue')->willReturn([$task]);
        $tasks->expects(self::exactly(2))->method('findByTaskKey')->willReturn($task);
        $runs = $this->createMock(ScheduledTaskRunStore::class);
        $runs->expects(self::once())->method('findExistingForDueTime')->willReturn($existing);
        $runs->expects(self::once())->method('pruneHistory')->willReturn(0);

        $runner = new ScheduledTaskRunner(
            $connection,
            $entityManager,
            $tasks,
            $runs,
            new ScheduledTaskHandlerRegistry([$handler]),
            new MockClock($now),
        );

        self::assertSame(1, $runner->runDueTasks('scheduler-02', $now));
        self::assertSame(ScheduledTaskRunStatus::Succeeded, $existing->getStatus());
        self::assertSame(2, $existing->getAttemptCount());
        self::assertSame($idempotencyKey, $existing->getIdempotencyKey());
    }

    /** @return array{ScheduledTask, \DateTimeImmutable} */
    private static function dueTask(): array
    {
        $createdAt = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $now = $createdAt->modify('+20 minutes');

        return [ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Sitemaps aktualisieren', 15, [], $createdAt), $now];
    }

    private function connectionWithLock(int $transactionCount = 2): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('t', 't');
        $connection->expects(self::exactly($transactionCount))
            ->method('transactional')
            ->willReturnCallback(fn (\Closure $operation): mixed => $operation($connection));

        return $connection;
    }
}
