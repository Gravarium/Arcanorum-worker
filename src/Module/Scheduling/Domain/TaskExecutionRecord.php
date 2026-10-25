<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

final readonly class TaskExecutionRecord
{
    public function __construct(
        public string $taskId,
        public \DateTimeImmutable $scheduledFor,
        public ExecutionState $state,
        public int $attempt,
        public ?ExecutionResultCode $resultCode,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public ?\DateTimeImmutable $leaseExpiresAt,
    ) {
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Task execution attempt must be positive.');
        }

        if (ExecutionState::Running === $state) {
            if (null !== $finishedAt || null === $leaseExpiresAt || null !== $resultCode) {
                throw new \InvalidArgumentException('Running task execution record is inconsistent.');
            }
        } elseif (null === $finishedAt || null === $resultCode || null !== $leaseExpiresAt) {
            throw new \InvalidArgumentException('Finished task execution record is inconsistent.');
        }
    }
}
