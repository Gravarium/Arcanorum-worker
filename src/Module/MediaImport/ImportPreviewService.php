<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class ImportPreviewService
{
    public function __construct(
        private ProviderAdapterRegistry $adapters,
        private ImportDraftStore $drafts,
        private ProviderRateLimiter $rateLimiter,
        private ImportClock $clock,
    ) {
    }

    public function preview(ImportSource $source, ?string $cursor = null, int $pageSize = 25): ImportPreview
    {
        if ($pageSize < 1 || $pageSize > 50) {
            throw new \InvalidArgumentException('The preview page size is invalid.');
        }

        $now = $this->clock->now();
        $retryAt = $this->rateLimiter->retryAt($source->provider, $now);
        if (null !== $retryAt) {
            $seconds = max(1, min(86400, $retryAt->getTimestamp() - $now->getTimestamp()));
            throw new ProviderException(ProviderError::RateLimited, $seconds);
        }

        $page = $this->adapters->forProvider($source->provider)->fetch($source, $cursor, $pageSize);
        if (count($page->videos) > $pageSize || ([] === $page->videos && null !== $page->nextCursor)) {
            throw new ProviderException(ProviderError::InvalidResponse);
        }

        $rows = [];
        $seen = [];
        foreach ($page->videos as $candidate) {
            if ($candidate->provider !== $source->provider) {
                throw new ProviderException(ProviderError::InvalidResponse);
            }

            $duplicateInPreview = isset($seen[$candidate->externalId]);
            $seen[$candidate->externalId] = true;
            $rows[] = new PreviewRow(
                $candidate,
                $this->drafts->hasProviderVideo($candidate->provider, $candidate->externalId),
                $duplicateInPreview,
            );
        }

        return new ImportPreview($rows, $page->nextCursor);
    }
}
