<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'scheduled_task_runs')]
#[ORM\Index(name: 'idx_scheduled_task_runs_recent', columns: ['started_at'])]
#[ORM\Index(name: 'idx_scheduled_task_runs_task_recent', columns: ['task_key', 'started_at'])]
#[ORM\UniqueConstraint(name: 'uniq_scheduled_task_run_due', columns: ['task_id', 'scheduled_for'])]
final class ScheduledTaskRun
{
    public const int MAX_ATTEMPTS = 10;
    private const int LEASE_SECONDS = 120;

    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: ScheduledTask::class)]
    #[ORM\JoinColumn(name: 'task_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ScheduledTask $task = null;

    #[ORM\Column(name: 'task_key', length: 80)]
    private string $taskKey;

    #[ORM\Column(name: 'task_name', length: 120)]
    private string $taskName;

    #[ORM\Column(name: 'finished_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 16, enumType: ScheduledTaskRunStatus::class)]
    private ScheduledTaskRunStatus $status;

    #[ORM\Column(name: 'scheduler_identity', length: 64)]
    private string $schedulerIdentity;

    #[ORM\Column(name: 'result_summary', type: Types::STRING, length: 500, nullable: true)]
    private ?string $resultSummary = null;

    #[ORM\Column(name: 'attempt_count', type: Types::INTEGER, options: ['default' => 1])]
    private int $attemptCount = 1;

    #[ORM\Column(name: 'last_attempt_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastAttemptAt;

    #[ORM\Column(name: 'lease_token_digest', length: 64, nullable: true)]
    private ?string $leaseTokenDigest = null;

    #[ORM\Column(name: 'lease_expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $leaseExpiresAt = null;

    #[ORM\Column(name: 'retry_after', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $retryAfter = null;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    /** Kept only in memory for the worker that acquired the current attempt. */
    private ?string $leaseToken = null;

    public function __construct(
        ScheduledTask $task,
        #[ORM\Column(name: 'scheduled_for', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $scheduledFor,
        #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $startedAt,
        string $schedulerIdentity,
    ) {
        self::assertSchedulerIdentity($schedulerIdentity);

        $this->id = bin2hex(random_bytes(16));
        $this->task = $task;
        $this->taskKey = $task->getTaskKey();
        $this->taskName = $task->getName();
        $this->schedulerIdentity = $schedulerIdentity;
        $this->lastAttemptAt = $startedAt;
        $this->status = ScheduledTaskRunStatus::Running;
        $this->assignLease($startedAt);
    }

    public function succeed(string $leaseToken, string $summary, \DateTimeImmutable $finishedAt): void
    {
        $this->assertLeaseOwner($leaseToken, $finishedAt);
        $this->finish(ScheduledTaskRunStatus::Succeeded, $summary, $finishedAt);
    }

    public function fail(string $leaseToken, string $summary, \DateTimeImmutable $finishedAt): void
    {
        $this->assertLeaseOwner($leaseToken, $finishedAt);
        $this->finish(ScheduledTaskRunStatus::Failed, $summary, $finishedAt);

        if ($this->attemptCount < self::MAX_ATTEMPTS) {
            $delay = min(3600, 30 * (2 ** min(7, $this->attemptCount - 1)));
            $this->retryAfter = $finishedAt->modify(sprintf('+%d seconds', $delay));
        }
    }

    public function isRetryAvailableAt(\DateTimeImmutable $now): bool
    {
        if ($this->attemptCount >= self::MAX_ATTEMPTS) {
            return false;
        }

        if (ScheduledTaskRunStatus::Running === $this->status) {
            return null !== $this->leaseExpiresAt && $this->leaseExpiresAt <= $now;
        }

        return ScheduledTaskRunStatus::Failed === $this->status
            && (null === $this->retryAfter || $this->retryAfter <= $now);
    }

    public function hasExpiredLeaseAtMaxAttempts(\DateTimeImmutable $now): bool
    {
        return ScheduledTaskRunStatus::Running === $this->status
            && $this->attemptCount >= self::MAX_ATTEMPTS
            && null !== $this->leaseExpiresAt
            && $this->leaseExpiresAt <= $now;
    }

    public function isExhausted(): bool
    {
        return ScheduledTaskRunStatus::Failed === $this->status && $this->attemptCount >= self::MAX_ATTEMPTS;
    }

    public function claimForRetry(string $schedulerIdentity, \DateTimeImmutable $now): bool
    {
        self::assertSchedulerIdentity($schedulerIdentity);
        if (!$this->isRetryAvailableAt($now)) {
            return false;
        }

        ++$this->attemptCount;
        $this->schedulerIdentity = $schedulerIdentity;
        $this->lastAttemptAt = $now;
        $this->finishedAt = null;
        $this->resultSummary = null;
        $this->status = ScheduledTaskRunStatus::Running;
        $this->assignLease($now);

        return true;
    }

    public function exhaustExpiredLease(\DateTimeImmutable $now): void
    {
        if (!$this->hasExpiredLeaseAtMaxAttempts($now)) {
            throw new \LogicException('Nur ein abgelaufener Lauf am Versuchslimit kann endgültig beendet werden.');
        }

        $this->status = ScheduledTaskRunStatus::Failed;
        $this->resultSummary = 'Der Auftrag wurde nach mehreren unterbrochenen Ausführungen angehalten.';
        $this->finishedAt = $now;
        $this->retryAfter = null;
        $this->clearLease();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTaskKey(): string
    {
        return $this->taskKey;
    }

    public function getTaskName(): string
    {
        return $this->taskName;
    }

    public function getScheduledFor(): \DateTimeImmutable
    {
        return $this->scheduledFor;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getLastAttemptAt(): \DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function getLeaseExpiresAt(): ?\DateTimeImmutable
    {
        return $this->leaseExpiresAt;
    }

    public function getRetryAfter(): ?\DateTimeImmutable
    {
        return $this->retryAfter;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getStatus(): ScheduledTaskRunStatus
    {
        return $this->status;
    }

    public function getSchedulerIdentity(): string
    {
        return $this->schedulerIdentity;
    }

    public function getResultSummary(): ?string
    {
        return $this->resultSummary;
    }

    public function getTask(): ?ScheduledTask
    {
        return $this->task;
    }

    public function takeLeaseToken(): string
    {
        if (null === $this->leaseToken) {
            throw new \LogicException('Der aktuelle Lauf besitzt kein im Speicher verfügbares Lease-Token.');
        }

        $token = $this->leaseToken;
        $this->leaseToken = null;

        return $token;
    }

    public function isOwnedBy(string $leaseToken): bool
    {
        return null !== $this->leaseTokenDigest
            && hash_equals($this->leaseTokenDigest, hash('sha256', $leaseToken));
    }

    public function ownsUnexpiredLeaseAt(string $leaseToken, \DateTimeImmutable $finishedAt): bool
    {
        return $this->isOwnedBy($leaseToken)
            && ScheduledTaskRunStatus::Running === $this->status
            && null !== $this->leaseExpiresAt
            && $this->leaseExpiresAt > $finishedAt;
    }

    /** Bei Wiederholungen dieses geplanten Laufs stabil. */
    public function getIdempotencyKey(): string
    {
        return hash('sha256', $this->taskKey."\0".$this->scheduledFor->format('Y-m-d H:i:s'));
    }

    public function detachFromTask(): void
    {
        $this->task = null;
    }

    private function assignLease(\DateTimeImmutable $now): void
    {
        $this->leaseToken = bin2hex(random_bytes(32));
        $this->leaseTokenDigest = hash('sha256', $this->leaseToken);
        $this->leaseExpiresAt = $now->modify(sprintf('+%d seconds', self::LEASE_SECONDS));
        $this->retryAfter = null;
    }

    private function clearLease(): void
    {
        $this->leaseToken = null;
        $this->leaseTokenDigest = null;
        $this->leaseExpiresAt = null;
    }

    private function assertLeaseOwner(string $leaseToken, \DateTimeImmutable $finishedAt): void
    {
        if (!$this->ownsUnexpiredLeaseAt($leaseToken, $finishedAt)) {
            throw new \LogicException('Der Aufgabenlauf wird nicht mehr von diesem Lease gehalten.');
        }
    }

    private static function assertSchedulerIdentity(string $schedulerIdentity): void
    {
        if (1 !== preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{1,63}\z/D', $schedulerIdentity)
            || false !== filter_var($schedulerIdentity, FILTER_VALIDATE_IP)
        ) {
            throw new \InvalidArgumentException('Die Scheduler-Identität ist ungültig.');
        }
    }

    private function finish(ScheduledTaskRunStatus $status, string $summary, \DateTimeImmutable $finishedAt): void
    {
        $summary = trim($summary);
        if ('' === $summary || !mb_check_encoding($summary, 'UTF-8') || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $summary)) {
            throw new \InvalidArgumentException('Die Aufgabenmeldung ist ungültig.');
        }

        $this->status = $status;
        $this->resultSummary = mb_substr($summary, 0, 500, 'UTF-8');
        $this->finishedAt = $finishedAt;
        $this->clearLease();
    }
}
