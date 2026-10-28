<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

use DateInterval;

final readonly class ImportRunner
{
    private const MAX_PAGE_SIZE = 50;
    private const LEASE_SECONDS = 120;

    public function __construct(
        private ImportJobStore $jobs,
        private ProviderAdapterRegistry $adapters,
        private ProviderRateLimiter $rateLimiter,
        private ImportClock $clock,
    ) {
    }

    public function run(string $jobId, string $workerId): ImportRunResult
    {
        $now = $this->clock->now();
        $claim = $this->jobs->claim($jobId, $workerId, $now, self::LEASE_SECONDS);
        if (null === $claim) {
            return new ImportRunResult(ImportRunStatus::NotClaimed);
        }

        $job = $claim->job;
        $retryAt = $this->rateLimiter->retryAt($job->source->provider, $now);
        if (null !== $retryAt) {
            if ($retryAt <= $now) {
                $retryAt = $now->add(new DateInterval('PT60S'));
            }

            $saved = $this->jobs->defer($claim, ProviderError::RateLimited, $retryAt, $now);

            return new ImportRunResult($saved ? ImportRunStatus::Deferred : ImportRunStatus::LeaseLost, failure: ProviderError::RateLimited);
        }

        $remaining = $job->maxResults - $job->processedResults;
        if ($remaining <= 0) {
            $newDraftCount = $this->jobs->commitPage($claim, [], null, true, $now);

            return new ImportRunResult(null === $newDraftCount ? ImportRunStatus::LeaseLost : ImportRunStatus::Completed);
        }

        $pageSize = min(self::MAX_PAGE_SIZE, $remaining);
        try {
            $page = $this->adapters->forProvider($job->source->provider)->fetch(
                $job->source,
                $job->cursor,
                $pageSize,
            );
            if (count($page->videos) > $pageSize || ([] === $page->videos && null !== $page->nextCursor)) {
                throw new ProviderException(ProviderError::InvalidResponse);
            }

            $seen = [];
            foreach ($page->videos as $video) {
                if ($video->provider !== $job->source->provider || isset($seen[$video->externalId])) {
                    throw new ProviderException(ProviderError::InvalidResponse);
                }
                $seen[$video->externalId] = true;
            }

            $complete = null === $page->nextCursor
                || $job->processedResults + count($page->videos) >= $job->maxResults;
            $nextCursor = $complete ? null : $page->nextCursor;
            $committedAt = $this->clock->now();
            $newDraftCount = $this->jobs->commitPage($claim, $page->videos, $nextCursor, $complete, $committedAt);
            if (null === $newDraftCount) {
                return new ImportRunResult(ImportRunStatus::LeaseLost, count($page->videos));
            }

            return new ImportRunResult(
                $complete ? ImportRunStatus::Completed : ImportRunStatus::Progressed,
                count($page->videos),
                $newDraftCount,
            );
        } catch (ProviderException $exception) {
            $failureTime = $this->clock->now();
            if (in_array($exception->failure, [ProviderError::RateLimited, ProviderError::Unavailable], true)) {
                $retryAfter = max(1, min(86400, $exception->retryAfterSeconds ?? 60));
                $saved = $this->jobs->defer(
                    $claim,
                    $exception->failure,
                    $failureTime->add(new DateInterval('PT' . $retryAfter . 'S')),
                    $failureTime,
                );

                return new ImportRunResult($saved ? ImportRunStatus::Deferred : ImportRunStatus::LeaseLost, failure: $exception->failure);
            }

            $saved = $this->jobs->fail($claim, $exception->failure, $failureTime);

            return new ImportRunResult($saved ? ImportRunStatus::Failed : ImportRunStatus::LeaseLost, failure: $exception->failure);
        } catch (\Throwable) {
            $failureTime = $this->clock->now();
            $saved = $this->jobs->fail($claim, ProviderError::Unavailable, $failureTime);

            return new ImportRunResult($saved ? ImportRunStatus::Failed : ImportRunStatus::LeaseLost, failure: ProviderError::Unavailable);
        }
    }
}
