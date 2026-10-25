<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

final readonly class TaskExecutionClaim
{
    public function __construct(
        public string $taskId,
        public \DateTimeImmutable $scheduledFor,
        public int $attempt,
        public string $leaseToken,
        public string $idempotencyKey,
    ) {
        if ($attempt < 1 || 1 !== preg_match('/\A[a-f0-9]{32}\z/D', $leaseToken) || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $idempotencyKey)) {
            throw new \InvalidArgumentException('Task execution claim is invalid.');
        }
    }
}
