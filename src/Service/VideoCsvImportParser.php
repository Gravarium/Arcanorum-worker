<?php

declare(strict_types=1);

namespace App\Service;

final class VideoCsvImportParser
{
    private const int MAX_FILE_BYTES = 2097152;
    private const int MAX_ROWS = 500;

    /** @var list<string> */
    private const array HEADERS = ['title', 'description', 'source_type', 'source_url', 'category', 'tags'];

    /**
     * @return array{
     *     rows: list<array{
     *         record: int,
     *         title: string,
     *         description: string,
     *         source_type: string,
     *         source_url: string,
     *         category: string,
     *         tags: string
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

            $header = fgetcsv($handle, self::MAX_FILE_BYTES + 1, ',', '"', '');
            if (!is_array($header)) {
                return ['rows' => [], 'errors' => ['Die CSV-Datei enthält keine Kopfzeile.']];
            }

            if (isset($header[0]) && str_starts_with($header[0], "\xEF\xBB\xBF")) {
                $header[0] = substr($header[0], 3);
            }
            foreach ($header as $headerValue) {
                if (!is_string($headerValue) || !mb_check_encoding($headerValue, 'UTF-8')) {
                    return [
                        'rows' => [],
                        'errors' => ['Die Kopfzeile enthält ungültige Zeichen.'],
                    ];
                }
            }
            $normalizedHeader = array_map(
                static fn (string $value): string => mb_strtolower(trim($value)),
                $header,
            );
            if (self::HEADERS !== $normalizedHeader) {
                return [
                    'rows' => [],
                    'errors' => ['Die Kopfzeile muss genau title, description, source_type, source_url, category, tags enthalten.'],
                ];
            }

            $rows = [];
            $errors = [];
            $recordNumber = 1;
            $dataRecordCount = 0;
            while (false !== ($record = fgetcsv($handle, self::MAX_FILE_BYTES + 1, ',', '"', ''))) {
                ++$recordNumber;
                if ([null] === $record) {
                    continue;
                }

                ++$dataRecordCount;
                if ($dataRecordCount > self::MAX_ROWS) {
                    $errors[] = sprintf('Die CSV-Datei darf höchstens %d Videozeilen enthalten.', self::MAX_ROWS);

                    return ['rows' => [], 'errors' => $errors];
                }
                if (count($record) !== count(self::HEADERS)) {
                    $errors[] = sprintf('Datensatz %d hat nicht genau sechs Spalten.', $recordNumber);

                    continue;
                }

                $values = array_map(static fn (mixed $value): string => is_string($value) ? trim($value, " \t\n\r") : '', $record);
                $validEncoding = array_all($values, fn ($value) => mb_check_encoding($value, 'UTF-8'));
                if (!$validEncoding) {
                    $errors[] = sprintf('Datensatz %d enthält ungültige UTF-8-Zeichen.', $recordNumber);

                    continue;
                }

                $rows[] = [
                    'record' => $recordNumber,
                    'title' => $values[0],
                    'description' => $values[1],
                    'source_type' => $values[2],
                    'source_url' => $values[3],
                    'category' => $values[4],
                    'tags' => $values[5],
                ];
            }

            return ['rows' => $rows, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }
}
