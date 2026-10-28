<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class ImportRunResult
{
    public function __construct(
        public ImportRunStatus $status,
        public int $processedCount = 0,
        public int $newDraftCount = 0,
        public ?ProviderError $failure = null,
    ) {
        if ($processedCount < 0 || $newDraftCount < 0 || $newDraftCount > $processedCount) {
            throw new \InvalidArgumentException('The import run result is invalid.');
        }
    }
}
