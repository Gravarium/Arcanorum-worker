<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class DraftReviewService
{
    public function __construct(
        private DraftApprovalPolicy $policy,
        private ImportDraftStore $drafts,
    ) {
    }

    public function approve(
        MediaProvider $provider,
        string $externalId,
        string $actorId,
        \DateTimeImmutable $approvedAt,
    ): bool {
        if (!$provider->acceptsVideoId($externalId)
            || 1 !== preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $actorId)
            || !$this->policy->mayApprove($actorId, $provider, $externalId)) {
            throw new \DomainException('The draft review is not authorized.');
        }

        return $this->drafts->approveDraftIfPending($provider, $externalId, $actorId, $approvedAt);
    }
}
