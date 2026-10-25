<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

use App\Module\Scheduling\Domain\TaskExecutionClaim;

final readonly class TaskExecutionContext
{
    public function __construct(public string $taskId, public \DateTimeImmutable $scheduledFor, public string $idempotencyKey)
    {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/D', $taskId) || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $idempotencyKey)) {
            throw new \InvalidArgumentException('Task execution idempotency key is invalid.');
        }
    }

    public static function fromClaim(TaskExecutionClaim $claim): self
    {
        return new self($claim->taskId, $claim->scheduledFor, $claim->idempotencyKey);
    }
}
