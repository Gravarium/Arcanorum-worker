<?php

declare(strict_types=1);

namespace App\Tests\Module\MediaImport;

use App\Module\MediaImport\ClaimedImportJob;
use App\Module\MediaImport\DraftApprovalPolicy;
use App\Module\MediaImport\DraftReviewService;
use App\Module\MediaImport\ImportClock;
use App\Module\MediaImport\ImportDraft;
use App\Module\MediaImport\ImportDraftStore;
use App\Module\MediaImport\ImportJob;
use App\Module\MediaImport\ImportJobControlPolicy;
use App\Module\MediaImport\ImportJobControlService;
use App\Module\MediaImport\ImportJobStatus;
use App\Module\MediaImport\ImportJobStore;
use App\Module\MediaImport\ImportPreviewService;
use App\Module\MediaImport\ImportRunner;
use App\Module\MediaImport\ImportRunStatus;
use App\Module\MediaImport\ImportSource;
use App\Module\MediaImport\MediaProvider;
use App\Module\MediaImport\ProviderAdapter;
use App\Module\MediaImport\ProviderAdapterRegistry;
use App\Module\MediaImport\ProviderError;
use App\Module\MediaImport\ProviderException;
use App\Module\MediaImport\ProviderPage;
use App\Module\MediaImport\ProviderRateLimiter;
use App\Module\MediaImport\SourceKind;
use App\Module\MediaImport\VideoCandidate;
use PHPUnit\Framework\TestCase;

final class ImportRunnerTest extends TestCase
{
    public const NOW = '2026-10-28T10:00:00+00:00';

    public function testRunnerCreatesDraftsAndAdvancesCursorAfterSuccessfulPage(): void
    {
        $candidate = self::candidate('abc12345', 'Original title');
        $jobs = new FakeImportJobStore(self::job());
        $drafts = new FakeImportDraftStore();
        $adapter = new FakeMediaImportAdapter(MediaProvider::YouTube, new ProviderPage([$candidate], 'next-page'));
        $runner = self::runner($jobs, $drafts, $adapter);

        $result = $runner->run(self::job()->id, 'worker_one');

        self::assertSame(ImportRunStatus::Progressed, $result->status);
        self::assertSame(1, $result->processedCount);
        self::assertSame(1, $result->newDraftCount);
        self::assertSame('next-page', $jobs->getJob()->cursor);
        self::assertSame(1, $jobs->getJob()->processedResults);
        self::assertSame(ImportJobStatus::Queued, $jobs->getJob()->status);
        self::assertSame(ImportDraft::STATUS, $drafts->statusFor(MediaProvider::YouTube, 'abc12345'));
    }

    public function testRetryIsIdempotentAndDoesNotOverwriteAnExistingProviderVideo(): void
    {
        $existing = new ImportDraft(self::job()->id, self::candidate('abc12345', 'Existing title'), new \DateTimeImmutable(self::NOW));
        $drafts = new FakeImportDraftStore();
        self::assertTrue($drafts->saveDraftIfAbsent($existing));
        $jobs = new FakeImportJobStore(self::job());
        $adapter = new FakeMediaImportAdapter(MediaProvider::YouTube, new ProviderPage([self::candidate('abc12345', 'Changed title')], null));

        $result = self::runner($jobs, $drafts, $adapter)->run(self::job()->id, 'worker_one');

        self::assertSame(ImportRunStatus::Completed, $result->status);
        self::assertSame(0, $result->newDraftCount);
        self::assertSame('Existing title', $drafts->draftFor(MediaProvider::YouTube, 'abc12345')?->candidate->title);
        self::assertSame(ImportDraft::STATUS, $drafts->statusFor(MediaProvider::YouTube, 'abc12345'));
    }

    public function testConcurrentWorkerCannotClaimAnAlreadyLeasedJob(): void
    {
        $jobs = new FakeImportJobStore(self::job());
        $claim = $jobs->claim(self::job()->id, 'worker_one', new \DateTimeImmutable(self::NOW), 120);
        self::assertInstanceOf(ClaimedImportJob::class, $claim);
        $adapter = new FakeMediaImportAdapter(MediaProvider::YouTube, new ProviderPage([], null));

        $result = self::runner($jobs, new FakeImportDraftStore(), $adapter)->run(self::job()->id, 'worker_two');

        self::assertSame(ImportRunStatus::NotClaimed, $result->status);
        self::assertSame(0, $adapter->calls);
    }

    public function testProviderRateLimitDefersWithoutMakingAProviderRequest(): void
    {
        $jobs = new FakeImportJobStore(self::job());
        $adapter = new FakeMediaImportAdapter(MediaProvider::YouTube, new ProviderPage([], null));
        $rateLimiter = new FakeMediaImportRateLimiter(new \DateTimeImmutable('2026-10-28T10:02:00+00:00'));

        $result = self::runner($jobs, new FakeImportDraftStore(), $adapter, $rateLimiter)->run(self::job()->id, 'worker_one');

        self::assertSame(ImportRunStatus::Deferred, $result->status);
        self::assertSame(ProviderError::RateLimited, $result->failure);
        self::assertSame(0, $adapter->calls);
        self::assertSame(ImportJobStatus::Queued, $jobs->getJob()->status);
        self::assertSame(ProviderError::RateLimited, $jobs->lastFailure);
    }

    public function testProviderFailureStoresOnlySafeCodeAndSchedulesRetry(): void
    {
        $jobs = new FakeImportJobStore(self::job());
        $adapter = new FakeMediaImportAdapter(
            MediaProvider::YouTube,
            new ProviderPage([], null),
            new ProviderException(ProviderError::RateLimited, 90),
        );

        $result = self::runner($jobs, new FakeImportDraftStore(), $adapter)->run(self::job()->id, 'worker_one');

        self::assertSame(ImportRunStatus::Deferred, $result->status);
        self::assertSame(ProviderError::RateLimited, $jobs->lastFailure);
        self::assertSame('2026-10-28T10:01:30+00:00', $jobs->retryAt?->format(DATE_ATOM));
    }

    public function testPreviewDoesNotPersistOrPublishCandidates(): void
    {
        $drafts = new FakeImportDraftStore();
        $adapter = new FakeMediaImportAdapter(
            MediaProvider::YouTube,
            new ProviderPage([self::candidate('abc12345', 'Preview only')], null),
        );
        $service = new ImportPreviewService(
            new ProviderAdapterRegistry([$adapter]),
            $drafts,
            new FakeMediaImportRateLimiter(),
            new FakeMediaImportClock(),
        );

        $preview = $service->preview(self::job()->source);

        self::assertCount(1, $preview->rows);
        self::assertFalse($preview->rows[0]->alreadyImported);
        self::assertSame([], $drafts->drafts);
    }

    public function testPreviewMarksStoredAndInPageDuplicatesWithoutPersisting(): void
    {
        $drafts = new FakeImportDraftStore();
        $candidate = self::candidate('abc12345', 'Already imported');
        $drafts->saveDraftIfAbsent(new ImportDraft(self::job()->id, $candidate, new \DateTimeImmutable(self::NOW)));
        $adapter = new FakeMediaImportAdapter(
            MediaProvider::YouTube,
            new ProviderPage([$candidate, $candidate], null),
        );
        $service = new ImportPreviewService(
            new ProviderAdapterRegistry([$adapter]),
            $drafts,
            new FakeMediaImportRateLimiter(),
            new FakeMediaImportClock(),
        );

        $preview = $service->preview(self::job()->source);

        self::assertCount(2, $preview->rows);
        self::assertTrue($preview->rows[0]->alreadyImported);
        self::assertFalse($preview->rows[0]->duplicateInPreview);
        self::assertTrue($preview->rows[1]->alreadyImported);
        self::assertTrue($preview->rows[1]->duplicateInPreview);
        self::assertCount(1, $drafts->drafts);
    }

    public function testDraftApprovalIsASeparateAuthorizedAction(): void
    {
        $drafts = new FakeImportDraftStore();
        $candidate = self::candidate('abc12345', 'Awaiting review');
        $drafts->saveDraftIfAbsent(new ImportDraft(self::job()->id, $candidate, new \DateTimeImmutable(self::NOW)));
        $policy = new FakeDraftApprovalPolicy(false);
        $review = new DraftReviewService($policy, $drafts);

        try {
            $review->approve(MediaProvider::YouTube, 'abc12345', 'moderator_one', new \DateTimeImmutable(self::NOW));
            self::fail('An unauthorized reviewer approved a draft.');
        } catch (\DomainException) {
            self::assertSame(ImportDraft::STATUS, $drafts->statusFor(MediaProvider::YouTube, 'abc12345'));
        }

        $policy->allowed = true;
        self::assertTrue($review->approve(MediaProvider::YouTube, 'abc12345', 'moderator_one', new \DateTimeImmutable(self::NOW)));
        self::assertSame('approved', $drafts->statusFor(MediaProvider::YouTube, 'abc12345'));
        self::assertFalse($drafts->isPublished(MediaProvider::YouTube, 'abc12345'));
    }

    public function testJobControlsRequireAuthorizationAndBoundStateTransitions(): void
    {
        $jobs = new FakeImportJobStore(self::job());
        $policy = new FakeImportJobControlPolicy();
        $control = new ImportJobControlService($policy, $jobs);
        $now = new \DateTimeImmutable(self::NOW);

        try {
            $control->pause(self::job()->id, 'moderator_one', $now);
            self::fail('An unauthorized actor paused an import.');
        } catch (\DomainException) {
            self::assertSame(ImportJobStatus::Queued, $jobs->getJob()->status);
        }

        $policy->mayPause = true;
        $policy->mayResume = true;
        $policy->mayCancel = true;
        self::assertTrue($control->pause(self::job()->id, 'moderator_one', $now));
        self::assertSame(ImportJobStatus::Paused, $jobs->getJob()->status);
        self::assertTrue($control->resume(self::job()->id, 'moderator_one', $now));
        self::assertSame(ImportJobStatus::Queued, $jobs->getJob()->status);
        self::assertTrue($control->cancel(self::job()->id, 'moderator_one', $now));
        self::assertSame(ImportJobStatus::Cancelled, $jobs->getJob()->status);
        self::assertFalse($control->resume(self::job()->id, 'moderator_one', $now));
    }

    public function testCancellationInvalidatesClaimBeforeDraftsCanBeCommitted(): void
    {
        $drafts = new FakeImportDraftStore();
        $jobs = new FakeImportJobStore(self::job());
        $jobs->useDrafts($drafts);
        $now = new \DateTimeImmutable(self::NOW);
        $claim = $jobs->claim(self::job()->id, 'worker_one', $now, 120);
        self::assertInstanceOf(ClaimedImportJob::class, $claim);

        $policy = new FakeImportJobControlPolicy();
        $policy->mayCancel = true;
        $control = new ImportJobControlService($policy, $jobs);
        self::assertTrue($control->cancel(self::job()->id, 'moderator_one', $now));

        self::assertNull($jobs->commitPage($claim, [self::candidate('abc12345', 'Late response')], null, true, $now));
        self::assertSame([], $drafts->drafts);
        self::assertSame(ImportJobStatus::Cancelled, $jobs->getJob()->status);
    }

    public function testRunnerRejectsAnOversizedProviderPageBeforeCommittingDrafts(): void
    {
        $jobs = new FakeImportJobStore(new ImportJob(
            self::job()->id,
            self::job()->source,
            1,
        ));
        $drafts = new FakeImportDraftStore();
        $adapter = new FakeMediaImportAdapter(
            MediaProvider::YouTube,
            new ProviderPage([
                self::candidate('abc12345', 'First'),
                self::candidate('def12345', 'Second'),
            ], null),
        );

        $result = self::runner($jobs, $drafts, $adapter)->run(self::job()->id, 'worker_one');

        self::assertSame(ImportRunStatus::Failed, $result->status);
        self::assertSame(ProviderError::InvalidResponse, $result->failure);
        self::assertSame([], $drafts->drafts);
    }

    public function testRunnerRejectsDuplicateIdsInsideOneProviderPage(): void
    {
        $jobs = new FakeImportJobStore(self::job());
        $drafts = new FakeImportDraftStore();
        $adapter = new FakeMediaImportAdapter(
            MediaProvider::YouTube,
            new ProviderPage([
                self::candidate('abc12345', 'First'),
                self::candidate('abc12345', 'Duplicate'),
            ], null),
        );

        $result = self::runner($jobs, $drafts, $adapter)->run(self::job()->id, 'worker_one');

        self::assertSame(ImportRunStatus::Failed, $result->status);
        self::assertSame(ProviderError::InvalidResponse, $result->failure);
        self::assertSame([], $drafts->drafts);
    }

    private static function job(): ImportJob
    {
        return new ImportJob(
            'job_123456789012',
            ImportSource::fromInput(MediaProvider::YouTube, SourceKind::Search, 'cats'),
            100,
        );
    }

    private static function candidate(string $id, string $title): VideoCandidate
    {
        return new VideoCandidate(MediaProvider::YouTube, $id, $title, '');
    }

    private static function runner(
        FakeImportJobStore $jobs,
        FakeImportDraftStore $drafts,
        FakeMediaImportAdapter $adapter,
        ?FakeMediaImportRateLimiter $rateLimiter = null,
    ): ImportRunner {
        $jobs->useDrafts($drafts);

        return new ImportRunner(
            $jobs,
            new ProviderAdapterRegistry([$adapter]),
            $rateLimiter ?? new FakeMediaImportRateLimiter(),
            new FakeMediaImportClock(),
        );
    }
}

final class FakeImportJobStore implements ImportJobStore
{
    public ?ImportJob $job;
    public bool $leaseAvailable = true;
    public ?ProviderError $lastFailure = null;
    public ?\DateTimeImmutable $retryAt = null;
    public FakeImportDraftStore $drafts;

    public function __construct(?ImportJob $job)
    {
        $this->job = $job;
        $this->drafts = new FakeImportDraftStore();
    }

    public function useDrafts(FakeImportDraftStore $drafts): void
    {
        $this->drafts = $drafts;
    }

    public function getJob(): ImportJob
    {
        if (null === $this->job) {
            throw new \LogicException('The fake import job is missing.');
        }

        return $this->job;
    }

    public function create(ImportJob $job): bool
    {
        if (null !== $this->job) {
            return false;
        }

        $this->job = $job;

        return true;
    }

    public function claim(string $jobId, string $workerId, \DateTimeImmutable $now, int $leaseSeconds): ?ClaimedImportJob
    {
        if (!$this->leaseAvailable || null === $this->job || $this->job->id !== $jobId
            || ImportJobStatus::Queued !== $this->job->status) {
            return null;
        }

        $this->leaseAvailable = false;
        $this->job = new ImportJob(
            $this->job->id,
            $this->job->source,
            $this->job->maxResults,
            $this->job->processedResults,
            $this->job->cursor,
            ImportJobStatus::Running,
            $this->job->version,
        );

        return new ClaimedImportJob($this->job, $workerId, 'lease_token_123456789');
    }

    public function commitPage(
        ClaimedImportJob $claim,
        array $videos,
        ?string $nextCursor,
        bool $complete,
        \DateTimeImmutable $now,
    ): ?int {
        if (!$this->owns($claim) || null === $this->job) {
            return null;
        }
        if ($this->job->processedResults + count($videos) > $this->job->maxResults
            || ($complete && null !== $nextCursor)
            || (!$complete && null === $nextCursor)) {
            throw new \InvalidArgumentException('The fake page checkpoint is invalid.');
        }

        $newDraftCount = 0;
        foreach ($videos as $video) {
            if ($this->drafts->saveDraftIfAbsent(new ImportDraft($this->job->id, $video, $now))) {
                ++$newDraftCount;
            }
        }

        $this->job = new ImportJob(
            $this->job->id,
            $this->job->source,
            $this->job->maxResults,
            $this->job->processedResults + count($videos),
            $nextCursor,
            $complete ? ImportJobStatus::Completed : ImportJobStatus::Queued,
            $this->job->version + 1,
        );
        $this->leaseAvailable = true;

        return $newDraftCount;
    }

    public function defer(
        ClaimedImportJob $claim,
        ProviderError $reason,
        \DateTimeImmutable $retryAt,
        \DateTimeImmutable $now,
    ): bool {
        if (!$this->owns($claim) || null === $this->job) {
            return false;
        }

        $this->lastFailure = $reason;
        $this->retryAt = $retryAt;
        $this->job = new ImportJob(
            $this->job->id,
            $this->job->source,
            $this->job->maxResults,
            $this->job->processedResults,
            $this->job->cursor,
            ImportJobStatus::Queued,
            $this->job->version + 1,
        );
        $this->leaseAvailable = true;

        return true;
    }

    public function fail(ClaimedImportJob $claim, ProviderError $reason, \DateTimeImmutable $now): bool
    {
        if (!$this->owns($claim) || null === $this->job) {
            return false;
        }

        $this->lastFailure = $reason;
        $this->job = new ImportJob(
            $this->job->id,
            $this->job->source,
            $this->job->maxResults,
            $this->job->processedResults,
            $this->job->cursor,
            ImportJobStatus::Failed,
            $this->job->version + 1,
        );
        $this->leaseAvailable = true;

        return true;
    }

    public function pause(string $jobId, string $actorId, \DateTimeImmutable $now): bool
    {
        if (null === $this->job || $this->job->id !== $jobId
            || !in_array($this->job->status, [ImportJobStatus::Queued, ImportJobStatus::Running], true)) {
            return false;
        }

        $this->job = new ImportJob(
            $this->job->id,
            $this->job->source,
            $this->job->maxResults,
            $this->job->processedResults,
            $this->job->cursor,
            ImportJobStatus::Paused,
            $this->job->version + 1,
        );
        $this->leaseAvailable = true;

        return true;
    }

    public function resume(string $jobId, string $actorId, \DateTimeImmutable $now): bool
    {
        if (null === $this->job || $this->job->id !== $jobId || ImportJobStatus::Paused !== $this->job->status) {
            return false;
        }

        $this->job = new ImportJob(
            $this->job->id,
            $this->job->source,
            $this->job->maxResults,
            $this->job->processedResults,
            $this->job->cursor,
            ImportJobStatus::Queued,
            $this->job->version + 1,
        );

        return true;
    }

    public function cancel(string $jobId, string $actorId, \DateTimeImmutable $now): bool
    {
        if (null === $this->job || $this->job->id !== $jobId
            || !in_array($this->job->status, [ImportJobStatus::Queued, ImportJobStatus::Running, ImportJobStatus::Paused], true)) {
            return false;
        }

        $this->job = new ImportJob(
            $this->job->id,
            $this->job->source,
            $this->job->maxResults,
            $this->job->processedResults,
            null,
            ImportJobStatus::Cancelled,
            $this->job->version + 1,
        );
        $this->leaseAvailable = true;

        return true;
    }

    private function owns(ClaimedImportJob $claim): bool
    {
        return !$this->leaseAvailable
            && null !== $this->job
            && $claim->job->id === $this->job->id
            && $claim->job->version === $this->job->version;
    }
}

final class FakeImportDraftStore implements ImportDraftStore
{
    /** @var array<string, ImportDraft> */
    public array $drafts = [];

    /** @var array<string, string> */
    private array $statuses = [];

    /** @var array<string, bool> */
    private array $published = [];

    public function saveDraftIfAbsent(ImportDraft $draft): bool
    {
        $key = $this->key($draft->candidate->provider, $draft->candidate->externalId);
        if (isset($this->drafts[$key])) {
            return false;
        }

        $this->drafts[$key] = $draft;
        $this->statuses[$key] = ImportDraft::STATUS;
        $this->published[$key] = false;

        return true;
    }

    public function hasProviderVideo(MediaProvider $provider, string $externalId): bool
    {
        return isset($this->drafts[$this->key($provider, $externalId)]);
    }

    public function approveDraftIfPending(
        MediaProvider $provider,
        string $externalId,
        string $actorId,
        \DateTimeImmutable $approvedAt,
    ): bool {
        $key = $this->key($provider, $externalId);
        if (!isset($this->drafts[$key]) || ImportDraft::STATUS !== ($this->statuses[$key] ?? null)) {
            return false;
        }

        $this->statuses[$key] = 'approved';

        return true;
    }

    public function statusFor(MediaProvider $provider, string $externalId): ?string
    {
        return $this->statuses[$this->key($provider, $externalId)] ?? null;
    }

    public function draftFor(MediaProvider $provider, string $externalId): ?ImportDraft
    {
        return $this->drafts[$this->key($provider, $externalId)] ?? null;
    }

    public function isPublished(MediaProvider $provider, string $externalId): bool
    {
        return $this->published[$this->key($provider, $externalId)] ?? false;
    }

    private function key(MediaProvider $provider, string $externalId): string
    {
        return $provider->value.':'.$externalId;
    }
}

final class FakeMediaImportAdapter implements ProviderAdapter
{
    public int $calls = 0;

    public function __construct(
        private MediaProvider $mediaProvider,
        private ProviderPage $page,
        private ?ProviderException $exception = null,
    ) {
    }

    public function provider(): MediaProvider
    {
        return $this->mediaProvider;
    }

    public function fetch(ImportSource $source, ?string $cursor, int $pageSize): ProviderPage
    {
        ++$this->calls;
        if (null !== $this->exception) {
            throw $this->exception;
        }

        return $this->page;
    }
}

final class FakeMediaImportRateLimiter implements ProviderRateLimiter
{
    public function __construct(private ?\DateTimeImmutable $nextAllowedAt = null)
    {
    }

    public function retryAt(MediaProvider $provider, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return $this->nextAllowedAt;
    }
}

final class FakeMediaImportClock implements ImportClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(ImportRunnerTest::NOW);
    }
}

final class FakeDraftApprovalPolicy implements DraftApprovalPolicy
{
    public function __construct(public bool $allowed)
    {
    }

    public function mayApprove(string $actorId, MediaProvider $provider, string $externalId): bool
    {
        return $this->allowed;
    }
}

final class FakeImportJobControlPolicy implements ImportJobControlPolicy
{
    public bool $mayPause = false;
    public bool $mayResume = false;
    public bool $mayCancel = false;

    public function mayPause(string $actorId, string $jobId): bool
    {
        return $this->mayPause;
    }

    public function mayResume(string $actorId, string $jobId): bool
    {
        return $this->mayResume;
    }

    public function mayCancel(string $actorId, string $jobId): bool
    {
        return $this->mayCancel;
    }
}
