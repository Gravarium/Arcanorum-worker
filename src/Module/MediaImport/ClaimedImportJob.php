<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class ClaimedImportJob
{
    public function __construct(
        public ImportJob $job,
        public string $workerId,
        public string $leaseToken,
    ) {
        if (ImportJobStatus::Running !== $job->status
            || 1 !== preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $workerId)
            || 1 !== preg_match('/\A[A-Za-z0-9_-]{16,128}\z/D', $leaseToken)) {
            throw new \InvalidArgumentException('The import claim is invalid.');
        }
    }
}
