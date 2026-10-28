<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

use DateTimeImmutable;

interface ImportJobStore
{
    public function create(ImportJob $job): bool;

    /**
     * Atomically claim one due queued job. A live lease cannot be claimed twice.
     * Expired leases may be reclaimed; a reclaimed claim must get a new token.
     */
    public function claim(string $jobId, string $workerId, DateTimeImmutable $now, int $leaseSeconds): ?ClaimedImportJob;

    /**
     * Atomically verify the active lease, insert drafts under the unique
     * (provider, external ID) key, and advance the job cursor/counter. A stale
     * or cancelled claim must insert no drafts and must not change the job.
     *
     * @param list<VideoCandidate> $videos
     * @return int|null Number of newly inserted drafts, or null when the lease was lost
     */
    public function commitPage(
        ClaimedImportJob $claim,
        array $videos,
        ?string $nextCursor,
        bool $complete,
        DateTimeImmutable $now,
    ): ?int;

    /** Persist only a bounded failure code and retry time; never an exception message. */
    public function defer(
        ClaimedImportJob $claim,
        ProviderError $reason,
        DateTimeImmutable $retryAt,
        DateTimeImmutable $now,
    ): bool;

    public function fail(ClaimedImportJob $claim, ProviderError $reason, DateTimeImmutable $now): bool;

    /** Pause atomically and invalidate a running claim while preserving its last committed cursor. */
    public function pause(string $jobId, string $actorId, DateTimeImmutable $now): bool;

    /** Resume only a paused job; it returns to the queue at the last committed cursor. */
    public function resume(string $jobId, string $actorId, DateTimeImmutable $now): bool;

    /** Cancel atomically and invalidate any active lease. */
    public function cancel(string $jobId, string $actorId, DateTimeImmutable $now): bool;
}
