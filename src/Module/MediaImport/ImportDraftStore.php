<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

use DateTimeImmutable;

interface ImportDraftStore
{
    /**
     * Atomically create a draft using (provider, external ID) as a unique key.
     * Existing content is never overwritten. Returns true only when inserted.
     */
    public function saveDraftIfAbsent(ImportDraft $draft): bool;

    public function hasProviderVideo(MediaProvider $provider, string $externalId): bool;

    /** Explicit moderation transition only; this must never publish or replace a draft. */
    public function approveDraftIfPending(
        MediaProvider $provider,
        string $externalId,
        string $actorId,
        DateTimeImmutable $approvedAt,
    ): bool;
}
