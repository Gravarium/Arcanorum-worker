<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class ImportJob
{
    public function __construct(
        public string $id,
        public ImportSource $source,
        public int $maxResults,
        public int $processedResults = 0,
        public ?string $cursor = null,
        public ImportJobStatus $status = ImportJobStatus::Queued,
        public int $version = 0,
    ) {
        if (1 !== preg_match('/\A[A-Za-z0-9_-]{16,80}\z/D', $id)
            || $maxResults < 1
            || $maxResults > 1000
            || $processedResults < 0
            || $processedResults > $maxResults
            || $version < 0
            || (null !== $cursor && ('' === $cursor || strlen($cursor) > 1024 || 1 === preg_match('/[\x00-\x1F\x7F]/', $cursor)))) {
            throw new \InvalidArgumentException('The import job is invalid.');
        }
    }
}
