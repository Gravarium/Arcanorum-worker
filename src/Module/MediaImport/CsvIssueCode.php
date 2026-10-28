<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

enum CsvIssueCode: string
{
    case InvalidColumnCount = 'invalid_column_count';
    case InvalidProvider = 'invalid_provider';
    case InvalidSourceType = 'invalid_source_type';
    case InvalidSource = 'invalid_source';
    case DuplicateSource = 'duplicate_source';
    case RowLimitReached = 'row_limit_reached';
}
