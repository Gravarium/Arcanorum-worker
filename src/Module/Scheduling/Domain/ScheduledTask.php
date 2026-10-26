<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

final readonly class ScheduledTask
{
    public function __construct(
        public string $id,
        public string $label,
        public TaskType $type,
        public ScheduleFrequency $frequency,
        public TaskStatus $status,
        public ?\DateTimeImmutable $nextRunAt,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            throw new \InvalidArgumentException('Scheduled task id is invalid.');
        }

        if ('' === trim($label) || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > 120) {
            throw new \InvalidArgumentException('Task label must contain 1 to 120 characters.');
        }

        if ($updatedAt < $createdAt) {
            throw new \InvalidArgumentException('Task update time cannot precede creation time.');
        }

        if ((TaskStatus::Active === $status) !== (null !== $nextRunAt)) {
            throw new \InvalidArgumentException('Only active tasks have a scheduled next run.');
        }
    }

    public static function create(string $id, string $label, TaskType $type, ScheduleFrequency $frequency, \DateTimeImmutable $at): self
    {
        return new self($id, $label, $type, $frequency, TaskStatus::Disabled, null, $at, $at);
    }

    public function revise(string $label, TaskType $type, ScheduleFrequency $frequency, \DateTimeImmutable $at): self
    {
        if (TaskStatus::Active === $this->status) {
            throw new \DomainException('Pause or disable a task before editing it.');
        }

        return new self($this->id, $label, $type, $frequency, $this->status, null, $this->createdAt, $at);
    }

    public function activate(\DateTimeImmutable $at): self
    {
        if (TaskStatus::Active === $this->status) {
            throw new \DomainException('Only paused or disabled tasks can be activated.');
        }

        return new self($this->id, $this->label, $this->type, $this->frequency, TaskStatus::Active, $this->frequency->nextAfter($at), $this->createdAt, $at);
    }

    public function pause(\DateTimeImmutable $at): self
    {
        if (TaskStatus::Active !== $this->status) {
            throw new \DomainException('Only active tasks can be paused.');
        }

        return new self($this->id, $this->label, $this->type, $this->frequency, TaskStatus::Paused, null, $this->createdAt, $at);
    }

    public function disable(\DateTimeImmutable $at): self
    {
        if (TaskStatus::Disabled === $this->status) {
            throw new \DomainException('Task is already disabled.');
        }

        return new self($this->id, $this->label, $this->type, $this->frequency, TaskStatus::Disabled, null, $this->createdAt, $at);
    }

    public function advanceAfterExecution(\DateTimeImmutable $scheduledFor, \DateTimeImmutable $nextRunAt, \DateTimeImmutable $at): self
    {
        if (TaskStatus::Active !== $this->status || null === $this->nextRunAt || $this->nextRunAt->getTimestamp() !== $scheduledFor->getTimestamp()) {
            return $this;
        }

        return new self($this->id, $this->label, $this->type, $this->frequency, $this->status, $nextRunAt, $this->createdAt, $at);
    }
}
