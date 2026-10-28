<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

enum CsvSourceError: string
{
    case TooLarge = 'csv_too_large';
    case InvalidEncoding = 'csv_invalid_encoding';
    case InvalidHeader = 'csv_invalid_header';
    case Unreadable = 'csv_unreadable';
}
