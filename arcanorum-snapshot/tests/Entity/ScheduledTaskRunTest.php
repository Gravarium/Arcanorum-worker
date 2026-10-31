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
        $leaseToken = $run->takeLeaseToken();
        $run->succeed($leaseToken, str_repeat('x', 501), $now->modify('+3 seconds'));

        self::assertSame(ScheduledTaskRunStatus::Succeeded, $run->getStatus());
        self::assertSame(500, mb_strlen((string) $run->getResultSummary(), 'UTF-8'));
        self::assertEquals($now->modify('+3 seconds'), $run->getFinishedAt());

        $this->expectException(\LogicException::class);
        $run->fail($leaseToken, 'retry', $now->modify('+4 seconds'));
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
        $run->fail($run->takeLeaseToken(), "private\x00exception", $now->modify('+1 second'));
    }

    public function testFailedAttemptUsesBoundedBackoffAndKeepsStableIdempotencyKey(): void
    {
        $now = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $task = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Sitemaps aktualisieren', 60, [], $now);
        $run = new ScheduledTaskRun($task, $now, $now, 'scheduler-01');
        $firstToken = $run->takeLeaseToken();
        $idempotencyKey = $run->getIdempotencyKey();

        $run->fail($firstToken, 'Temporärer Providerfehler.', $now->modify('+5 seconds'));

        self::assertSame(1, $run->getAttemptCount());
        self::assertEquals($now->modify('+35 seconds'), $run->getRetryAfter());
        self::assertFalse($run->isRetryAvailableAt($now->modify('+34 seconds')));
        self::assertTrue($run->claimForRetry('scheduler-02', $now->modify('+35 seconds')));
        $secondToken = $run->takeLeaseToken();
        self::assertSame(2, $run->getAttemptCount());
        self::assertSame($idempotencyKey, $run->getIdempotencyKey());
        self::assertFalse($run->isOwnedBy($firstToken));
        self::assertTrue($run->isOwnedBy($secondToken));
    }

    public function testExpiredLeaseCanBeReclaimedAndOldAttemptCannotComplete(): void
    {
        $now = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $task = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Sitemaps aktualisieren', 60, [], $now);
        $run = new ScheduledTaskRun($task, $now, $now, 'scheduler-01');
        $staleToken = $run->takeLeaseToken();
        $reclaimedAt = $now->modify('+120 seconds');

        self::assertTrue($run->claimForRetry('scheduler-02', $reclaimedAt));
        self::assertSame(2, $run->getAttemptCount());

        $this->expectException(\LogicException::class);
        $run->succeed($staleToken, 'A stale handler must not overwrite a newer attempt.', $reclaimedAt);
    }

    public function testCurrentLeaseCannotCompleteAtOrAfterItsExpiry(): void
    {
        $now = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $task = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Sitemaps aktualisieren', 60, [], $now);
        $run = new ScheduledTaskRun($task, $now, $now, 'scheduler-01');
        $leaseToken = $run->takeLeaseToken();
        $expiresAt = $now->modify('+120 seconds');

        self::assertTrue($run->ownsUnexpiredLeaseAt($leaseToken, $expiresAt->modify('-1 microsecond')));
        self::assertFalse($run->ownsUnexpiredLeaseAt($leaseToken, $expiresAt));
        self::assertFalse($run->ownsUnexpiredLeaseAt($leaseToken, $expiresAt->modify('+1 second')));

        $this->expectException(\LogicException::class);
        $run->succeed($leaseToken, 'The expired handler must not complete.', $expiresAt);
    }

    public function testTenFailedAttemptsStopRetrying(): void
    {
        $now = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $task = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Sitemaps aktualisieren', 60, [], $now);
        $run = new ScheduledTaskRun($task, $now, $now, 'scheduler-01');
        $attemptStartedAt = $now;

        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $finishedAt = $attemptStartedAt->modify('+1 second');
            $run->fail($run->takeLeaseToken(), 'Temporärer Fehler.', $finishedAt);
            if (10 === $attempt) {
                break;
            }
            $retryAt = $run->getRetryAfter();
            self::assertInstanceOf(\DateTimeImmutable::class, $retryAt);
            self::assertTrue($run->claimForRetry('scheduler-01', $retryAt));
            $attemptStartedAt = $retryAt;
        }

        self::assertSame(10, $run->getAttemptCount());
        self::assertTrue($run->isExhausted());
        self::assertNull($run->getRetryAfter());
        self::assertNull($run->getLeaseExpiresAt());
    }

    public function testExpiredTenthLeaseIsRecordedAsExhausted(): void
    {
        $now = new \DateTimeImmutable('2026-10-28T12:00:00+00:00');
        $task = ScheduledTask::create(ScheduledTaskType::SitemapRefresh, 'Sitemaps aktualisieren', 60, [], $now);
        $run = new ScheduledTaskRun($task, $now, $now, 'scheduler-01');
        $attemptStartedAt = $now;

        for ($attempt = 1; $attempt < ScheduledTaskRun::MAX_ATTEMPTS; ++$attempt) {
            $finishedAt = $attemptStartedAt->modify('+1 second');
            $run->fail($run->takeLeaseToken(), 'Temporärer Fehler.', $finishedAt);
            $retryAt = $run->getRetryAfter();
            self::assertInstanceOf(\DateTimeImmutable::class, $retryAt);
            self::assertTrue($run->claimForRetry('scheduler-01', $retryAt));
            $attemptStartedAt = $retryAt;
        }

        $recoveredAt = $attemptStartedAt->modify('+120 seconds');
        self::assertTrue($run->hasExpiredLeaseAtMaxAttempts($recoveredAt));
        $run->exhaustExpiredLease($recoveredAt);

        self::assertTrue($run->isExhausted());
        self::assertSame('Der Auftrag wurde nach mehreren unterbrochenen Ausführungen angehalten.', $run->getResultSummary());
        self::assertNull($run->getLeaseExpiresAt());
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
