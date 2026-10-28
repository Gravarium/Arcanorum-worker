<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

use DateTimeImmutable;

final readonly class ImportJobControlService
{
    public function __construct(
        private ImportJobControlPolicy $policy,
        private ImportJobStore $jobs,
    ) {
    }

    public function pause(string $jobId, string $actorId, DateTimeImmutable $now): bool
    {
        $this->assertIdentifiers($jobId, $actorId);
        if (!$this->policy->mayPause($actorId, $jobId)) {
            throw new \DomainException('The import action is not authorized.');
        }

        return $this->jobs->pause($jobId, $actorId, $now);
    }

    public function resume(string $jobId, string $actorId, DateTimeImmutable $now): bool
    {
        $this->assertIdentifiers($jobId, $actorId);
        if (!$this->policy->mayResume($actorId, $jobId)) {
            throw new \DomainException('The import action is not authorized.');
        }

        return $this->jobs->resume($jobId, $actorId, $now);
    }

    public function cancel(string $jobId, string $actorId, DateTimeImmutable $now): bool
    {
        $this->assertIdentifiers($jobId, $actorId);
        if (!$this->policy->mayCancel($actorId, $jobId)) {
            throw new \DomainException('The import action is not authorized.');
        }

        return $this->jobs->cancel($jobId, $actorId, $now);
    }

    private function assertIdentifiers(string $jobId, string $actorId): void
    {
        if (1 !== preg_match('/\A[A-Za-z0-9_-]{16,80}\z/D', $jobId)
            || 1 !== preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $actorId)) {
            throw new \InvalidArgumentException('The import action identifiers are invalid.');
        }
    }
}
