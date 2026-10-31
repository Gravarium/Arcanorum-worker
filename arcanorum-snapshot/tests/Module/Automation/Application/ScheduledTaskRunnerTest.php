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
        $entityManager->expects(self::once())
            ->method('find')
            ->with(ScheduledTask::class, $task->getId(), LockMode::PESSIMISTIC_WRITE)
            ->willReturn($task);
        $persisted = [];
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::never())->method('clear');

        $tasks = $this->createMock(ScheduledTaskStore::class);
        $tasks->expects(self::once())->method('findDue')->with($now, 10)->willReturn([$task]);
        $tasks->expects(self::once())->method('findByTaskKey')->with($task->getTaskKey())->willReturn($task);
        $runs = $this->createMock(ScheduledTaskRunStore::class);
        $runs->expects(self::once())->method('markStaleRunning')->willReturn(0);
        $runs->expects(self::once())->method('findExistingForDueTime')->with($task, $task->getNextRunAt())->willReturn(null);
        $runs->expects(self::once())->method('pruneHistory')->with(200)->willReturn(0);

        $runner = new ScheduledTaskRunner(
            $connection,
            $entityManager,
            $tasks,
            $runs,
            new ScheduledTaskHandlerRegistry([$handler]),
        );

        self::assertSame(1, $runner->runDueTasks('scheduler-01', $now));
        self::assertCount(1, $persisted);
        self::assertInstanceOf(ScheduledTaskRun::class, $persisted[0]);
        self::assertSame(ScheduledTaskRunStatus::Succeeded, $persisted[0]->getStatus());
        self::assertSame('Both sitemaps were refreshed.', $persisted[0]->getResultSummary());
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
        $runs->expects(self::never())->method('markStaleRunning');

        $runner = new ScheduledTaskRunner(
            $connection,
            $entityManager,
            $tasks,
            $runs,
            new ScheduledTaskHandlerRegistry([]),
        );

        self::assertSame(0, $runner->runDueTasks('scheduler-01', $now));
    }

    public function testExistingDueRunAdvancesScheduleWithoutDuplicateExecution(): void
    {
        [$task, $now] = self::dueTask();
        $existing = new ScheduledTaskRun($task, $task->getNextRunAt(), $now, 'scheduler-01');
        $connection = $this->connectionWithLock();
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
        );

        self::assertSame(0, $runner->runDueTasks('scheduler-01', $now));
        self::assertEquals($now->modify('+15 minutes'), $task->getNextRunAt());
    }

    public function testHandlerFailureStoresOnlyAGenericSafeResult(): void
    {
        [$task, $now] = self::dueTask();
        $handler = $this->createMock(ScheduledTaskHandlerInterface::class);
        $handler->method('type')->willReturn(ScheduledTaskType::SitemapRefresh);
        $handler->expects(self::once())->method('execute')->willThrowException(new \RuntimeException('provider-secret-response'));
        $connection = $this->connectionWithLock();
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($task);
        $persisted = [];
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
        $runs->expects(self::once())->method('markStaleRunning')->willReturn(0);
        $runs->expects(self::once())->method('findExistingForDueTime')->willReturn(null);
        $runs->expects(self::once())->method('findRunById')->willReturnCallback(static function (string $id) use (&$persisted): ?ScheduledTaskRun {
            $run = $persisted[0] ?? null;

            return $run instanceof ScheduledTaskRun && $run->getId() === $id ? $run : null;
        });
        $runs->expects(self::once())->method('pruneHistory')->willReturn(0);

        $runner = new ScheduledTaskRunner(
            $connection,
            $entityManager,
            $tasks,
            $runs,
            new ScheduledTaskHandlerRegistry([$handler]),
        );

        self::assertSame(1, $runner->runDueTasks('scheduler-01', $now));
        self::assertInstanceOf(ScheduledTaskRun::class, $persisted[0]);
        self::assertSame(ScheduledTaskRunStatus::Failed, $persisted[0]->getStatus());
        self::assertStringNotContainsString('provider-secret-response', (string) $persisted[0]->getResultSummary());
        self::assertSame('Der Auftrag ist fehlgeschlagen. Prüfe die Server- und Anbieterkonfiguration.', $persisted[0]->getResultSummary());
    }

    /** @return array{ScheduledTask, \DateTimeImmutable} */
    private static function dueTask(): array
    {
        $createdAt = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $now = $createdAt->modify('+20 minutes');

        return [ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Sitemaps aktualisieren', 15, [], $createdAt), $now];
    }

    private function connectionWithLock(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls('t', 't');
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (\Closure $operation): mixed => $operation($connection));

        return $connection;
    }
}
