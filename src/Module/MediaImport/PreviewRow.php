<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class PreviewRow
{
    public function __construct(
        public VideoCandidate $candidate,
        public bool $alreadyImported,
        public bool $duplicateInPreview = false,
    ) {
    }
}
