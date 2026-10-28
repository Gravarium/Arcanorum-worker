<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class CsvImportResult
{
    /**
     * @param list<ImportSource> $sources
     * @param list<CsvRowIssue>  $issues
     */
    public function __construct(
        public array $sources,
        public array $issues,
    ) {
    }
}
