<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

use DateTimeImmutable;

final readonly class ImportDraft
{
    public const STATUS = 'draft';

    public function __construct(
        public string $jobId,
        public VideoCandidate $candidate,
        public DateTimeImmutable $createdAt,
    ) {
        if (1 !== preg_match('/\A[A-Za-z0-9_-]{16,80}\z/D', $jobId)) {
            throw new \InvalidArgumentException('The import job identifier is invalid.');
        }
    }

    public function status(): string
    {
        return self::STATUS;
    }
}
