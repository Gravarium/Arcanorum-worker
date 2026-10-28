<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final readonly class CsvRowIssue
{
    public function __construct(
        public int $rowNumber,
        public CsvIssueCode $code,
    ) {
        if ($rowNumber < 2) {
            throw new \InvalidArgumentException('CSV row numbers start at the first data row.');
        }
    }
}
