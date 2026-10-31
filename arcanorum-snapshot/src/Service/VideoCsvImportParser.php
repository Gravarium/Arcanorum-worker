<?php

declare(strict_types=1);

namespace App\Service;

final class VideoCsvImportParser
{
    private const int MAX_FILE_BYTES = 2097152;
    private const int MAX_ROWS = 500;

    /** @var list<string> */
    private const array HEADERS = ['title', 'description', 'source_type', 'source_url', 'category', 'tags'];

    /** @var list<string> */
    private const array EXTENDED_HEADERS = ['title', 'description', 'source_type', 'source_url', 'category', 'tags', 'thumbnail_url', 'duration_seconds'];

    /** @var list<string> */
    private const array LEGACY_COLUMNS = ['source_url', 'title', 'description', 'tags', 'thumbnail_url', 'duration_seconds'];

    /**
     * @return array{
     *     rows: list<array{
     *         record: int,
     *         title: string,
     *         description: string,
     *         source_type: string,
     *         source_url: string,
     *         category: string,
     *         tags: string,
     *         thumbnail_url: string,
     *         duration_seconds: string
     *     }>,
     *     errors: list<string>
     * }
     */
    public function parse(string $contents): array
    {
        if (strlen($contents) > self::MAX_FILE_BYTES) {
            return ['rows' => [], 'errors' => ['Die CSV-Datei darf höchstens 2 MiB groß sein.']];
        }

        $handle = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (false === $handle) {
            return ['rows' => [], 'errors' => ['Die CSV-Datei konnte nicht gelesen werden.']];
        }

        try {
            $written = fwrite($handle, $contents);
            if (false === $written || $written !== strlen($contents)) {
                return ['rows' => [], 'errors' => ['Die CSV-Datei konnte nicht gelesen werden.']];
            }
            rewind($handle);

            $firstRecord = fgetcsv($handle, self::MAX_FILE_BYTES + 1, ',', '"', '');
            if (!is_array($firstRecord)) {
                return ['rows' => [], 'errors' => ['Die CSV-Datei enthält keine Kopfzeile.']];
            }

            if (isset($firstRecord[0]) && str_starts_with($firstRecord[0], "\xEF\xBB\xBF")) {
                $firstRecord[0] = substr($firstRecord[0], 3);
            }
            foreach ($firstRecord as $headerValue) {
                if (!is_string($headerValue) || !mb_check_encoding($headerValue, 'UTF-8')) {
                    return [
                        'rows' => [],
                        'errors' => ['Die erste CSV-Zeile enthält ungültige Zeichen.'],
                    ];
                }
            }
            $normalizedFirstRecord = array_map(
                static fn (string $value): string => mb_strtolower(trim($value)),
                $firstRecord,
            );

            $headerNames = match ($normalizedFirstRecord) {
                self::HEADERS => self::HEADERS,
                self::EXTENDED_HEADERS => self::EXTENDED_HEADERS,
                default => null,
            };
            $hasHeader = null !== $headerNames;
            if (!$hasHeader && self::looksLikeUnsupportedHeader($normalizedFirstRecord)) {
                return [
                    'rows' => [],
                    'errors' => ['Die Kopfzeile muss genau title, description, source_type, source_url, category, tags oder zusätzlich thumbnail_url, duration_seconds enthalten.'],
                ];
            }

            if (!$hasHeader && count($firstRecord) !== count(self::LEGACY_COLUMNS)) {
                return [
                    'rows' => [],
                    'errors' => ['Die Datei muss Arcanorums Kopfzeilenformat oder Legacys sechs Spalten ohne Kopfzeile verwenden.'],
                ];
            }

            $columnNames = $headerNames ?? self::LEGACY_COLUMNS;
            $expectedColumns = count($columnNames);
            $pendingRecord = $hasHeader ? null : $firstRecord;

            $rows = [];
            $errors = [];
            $recordNumber = $hasHeader ? 1 : 0;
            $dataRecordCount = 0;
            while (true) {
                $record = $pendingRecord ?? fgetcsv($handle, self::MAX_FILE_BYTES + 1, ',', '"', '');
                $pendingRecord = null;
                if (false === $record) {
                    break;
                }

                ++$recordNumber;
                if ([null] === $record) {
                    continue;
                }

                ++$dataRecordCount;
                if ($dataRecordCount > self::MAX_ROWS) {
                    $errors[] = sprintf('Die CSV-Datei darf höchstens %d Videozeilen enthalten.', self::MAX_ROWS);

                    return ['rows' => [], 'errors' => $errors];
                }
                if (count($record) !== $expectedColumns) {
                    $errors[] = sprintf('Datensatz %d hat nicht genau %d Spalten.', $recordNumber, $expectedColumns);

                    continue;
                }

                $values = array_map(static fn (mixed $value): string => is_string($value) ? trim($value, " \t\n\r") : '', $record);
                $validEncoding = array_all($values, fn ($value) => mb_check_encoding($value, 'UTF-8'));
                if (!$validEncoding) {
                    $errors[] = sprintf('Datensatz %d enthält ungültige UTF-8-Zeichen.', $recordNumber);

                    continue;
                }

                if ($hasHeader) {
                    $row = array_combine($columnNames, $values);
                    $rows[] = [
                        'record' => $recordNumber,
                        'title' => $row['title'],
                        'description' => $row['description'],
                        'source_type' => $row['source_type'],
                        'source_url' => $row['source_url'],
                        'category' => $row['category'],
                        'tags' => $row['tags'],
                        'thumbnail_url' => $row['thumbnail_url'] ?? '',
                        'duration_seconds' => $row['duration_seconds'] ?? '',
                    ];
                } else {
                    $row = array_combine(self::LEGACY_COLUMNS, $values);
                    $rows[] = [
                        'record' => $recordNumber,
                        'title' => $row['title'],
                        'description' => $row['description'],
                        'source_type' => '',
                        'source_url' => $row['source_url'],
                        'category' => '',
                        'tags' => $row['tags'],
                        'thumbnail_url' => $row['thumbnail_url'],
                        'duration_seconds' => $row['duration_seconds'],
                    ];
                }
            }

            return ['rows' => $rows, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string> $firstRow */
    private static function looksLikeUnsupportedHeader(array $firstRow): bool
    {
        $first = $firstRow[0] ?? '';

        return in_array($first, ['title', 'url', 'video_url', 'video url', 'source_url'], true);
    }
}
