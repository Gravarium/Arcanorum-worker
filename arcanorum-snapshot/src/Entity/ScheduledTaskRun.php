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

    public function __construct(
        ScheduledTask $task,
        #[ORM\Column(name: 'scheduled_for', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $scheduledFor,
        #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $startedAt,
        string $schedulerIdentity,
    ) {
        if (1 !== preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{1,63}\z/D', $schedulerIdentity)
            || false !== filter_var($schedulerIdentity, FILTER_VALIDATE_IP)
        ) {
            throw new \InvalidArgumentException('Die Scheduler-Identität ist ungültig.');
        }

        $this->id = bin2hex(random_bytes(16));
        $this->task = $task;
        $this->taskKey = $task->getTaskKey();
        $this->taskName = $task->getName();
        $this->schedulerIdentity = $schedulerIdentity;
        $this->status = ScheduledTaskRunStatus::Running;
    }

    public function succeed(string $summary, \DateTimeImmutable $finishedAt): void
    {
        $this->finish(ScheduledTaskRunStatus::Succeeded, $summary, $finishedAt);
    }

    public function fail(string $summary, \DateTimeImmutable $finishedAt): void
    {
        $this->finish(ScheduledTaskRunStatus::Failed, $summary, $finishedAt);
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

    public function detachFromTask(): void
    {
        $this->task = null;
    }

    private function finish(ScheduledTaskRunStatus $status, string $summary, \DateTimeImmutable $finishedAt): void
    {
        if (ScheduledTaskRunStatus::Running !== $this->status) {
            throw new \LogicException('Ein abgeschlossener Aufgabenlauf kann nicht erneut geändert werden.');
        }

        $summary = trim($summary);
        if ('' === $summary || !mb_check_encoding($summary, 'UTF-8') || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $summary)) {
            throw new \InvalidArgumentException('Die Aufgabenmeldung ist ungültig.');
        }

        $this->status = $status;
        $this->resultSummary = mb_substr($summary, 0, 500, 'UTF-8');
        $this->finishedAt = $finishedAt;
    }
}
