<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ScheduledTask;
use App\Entity\ScheduledTaskRun;
use App\Entity\ScheduledTaskRunStatus;
use App\Entity\ScheduledTaskType;
use PHPUnit\Framework\TestCase;

final class ScheduledTaskRunTest extends TestCase
{
    public function testSuccessfulRunStoresBoundedSummaryAndImmutableCompletion(): void
    {
        $now = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $task = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Sitemaps aktualisieren', 60, [], $now);
        $run = new ScheduledTaskRun($task, $now, $now, 'scheduler-01');
        $run->succeed(str_repeat('x', 501), $now->modify('+3 seconds'));

        self::assertSame(ScheduledTaskRunStatus::Succeeded, $run->getStatus());
        self::assertSame(500, mb_strlen((string) $run->getResultSummary(), 'UTF-8'));
        self::assertEquals($now->modify('+3 seconds'), $run->getFinishedAt());

        $this->expectException(\LogicException::class);
        $run->fail('retry', $now->modify('+4 seconds'));
    }

    public function testRunRequiresSafeNonIpSchedulerIdentityAndSummary(): void
    {
        $now = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $task = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Sitemaps aktualisieren', 60, [], $now);

        try {
            new ScheduledTaskRun($task, $now, $now, '10.0.0.1');
            self::fail('Scheduler identity must not contain client network addresses.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Die Scheduler-Identität ist ungültig.', $exception->getMessage());
        }

        $run = new ScheduledTaskRun($task, $now, $now, 'scheduler-01');
        $this->expectException(\InvalidArgumentException::class);
        $run->fail("private\x00exception", $now->modify('+1 second'));
    }

    public function testRunKeepsItsAuditSnapshotWhenItsTaskIsRemoved(): void
    {
        $now = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $task = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Sitemaps aktualisieren', 60, [], $now);
        $run = new ScheduledTaskRun($task, $now, $now, 'scheduler-01');

        $run->detachFromTask();

        self::assertNull($run->getTask());
        self::assertSame($task->getTaskKey(), $run->getTaskKey());
        self::assertSame($task->getName(), $run->getTaskName());
    }
}
