<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class ImportPreview
{
    /**
     * @param list<PreviewRow> $rows
     */
    public function __construct(
        public array $rows,
        public ?string $nextCursor,
    ) {
    }
}
