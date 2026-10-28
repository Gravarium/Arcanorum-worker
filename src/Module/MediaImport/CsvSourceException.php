<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final class CsvSourceException extends \RuntimeException
{
    public function __construct(public readonly CsvSourceError $failure)
    {
        parent::__construct('The CSV source cannot be processed.');
    }
}
