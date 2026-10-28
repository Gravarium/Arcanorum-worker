<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

final class CsvSourceParser
{
    private const MAX_BYTES = 1_048_576;
    private const MAX_ROWS = 500;
    private const MAX_RECORD_BYTES = 8192;

    public function parse(string $contents): CsvImportResult
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new CsvSourceException(CsvSourceError::TooLarge);
        }
        if (1 !== preg_match('//u', $contents)) {
            throw new CsvSourceException(CsvSourceError::InvalidEncoding);
        }

        $stream = fopen('php://temp', 'w+b');
        if (false === $stream || strlen($contents) !== fwrite($stream, $contents) || !rewind($stream)) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new CsvSourceException(CsvSourceError::Unreadable);
        }

        try {
            $header = fgetcsv($stream, self::MAX_RECORD_BYTES, ',', '"', '\\');
            if (false === $header) {
                throw new CsvSourceException(CsvSourceError::InvalidHeader);
            }

            if (isset($header[0])) {
                $header[0] = preg_replace('/\A\xEF\xBB\xBF/', '', $header[0]) ?? $header[0];
            }
            if (['provider', 'source_type', 'source'] !== array_map(static fn (mixed $cell): mixed => is_string($cell) ? trim($cell) : $cell, $header)) {
                throw new CsvSourceException(CsvSourceError::InvalidHeader);
            }

            $sources = [];
            $issues = [];
            $seen = [];
            $rowNumber = 1;
            while (false !== ($row = fgetcsv($stream, self::MAX_RECORD_BYTES, ',', '"', '\\'))) {
                ++$rowNumber;
                if ([null] === $row || [''] === $row) {
                    continue;
                }
                if ($rowNumber > self::MAX_ROWS + 1) {
                    $issues[] = new CsvRowIssue($rowNumber, CsvIssueCode::RowLimitReached);
                    break;
                }
                if (3 !== count($row) || !is_string($row[0] ?? null) || !is_string($row[1] ?? null) || !is_string($row[2] ?? null)) {
                    $issues[] = new CsvRowIssue($rowNumber, CsvIssueCode::InvalidColumnCount);
                    continue;
                }

                $provider = MediaProvider::tryFrom(trim($row[0]));
                if (null === $provider) {
                    $issues[] = new CsvRowIssue($rowNumber, CsvIssueCode::InvalidProvider);
                    continue;
                }
                $kind = SourceKind::tryFrom(trim($row[1]));
                if (null === $kind) {
                    $issues[] = new CsvRowIssue($rowNumber, CsvIssueCode::InvalidSourceType);
                    continue;
                }

                try {
                    $source = ImportSource::fromInput($provider, $kind, $row[2]);
                } catch (\InvalidArgumentException) {
                    $issues[] = new CsvRowIssue($rowNumber, CsvIssueCode::InvalidSource);
                    continue;
                }

                $key = $source->provider->value . ':' . $source->kind->value . ':' . $source->reference;
                if (isset($seen[$key])) {
                    $issues[] = new CsvRowIssue($rowNumber, CsvIssueCode::DuplicateSource);
                    continue;
                }

                $seen[$key] = true;
                $sources[] = $source;
            }

            return new CsvImportResult($sources, $issues);
        } finally {
            fclose($stream);
        }
    }
}
