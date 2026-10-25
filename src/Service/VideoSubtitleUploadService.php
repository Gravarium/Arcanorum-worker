<?php

declare(strict_types=1);

namespace App\Service;

use App\Platform\Hosting\PrivateObjectStorageInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class VideoSubtitleUploadService
{
    private const int MAX_FILE_BYTES = 1048576;

    public function __construct(private PrivateObjectStorageInterface $storage)
    {
    }

    /**
     * @return array{object_id: string, size: int, sha256: string}
     */
    public function store(UploadedFile $upload): array
    {
        if (!$upload->isValid() || 'vtt' !== strtolower($upload->getClientOriginalExtension())) {
            throw new \InvalidArgumentException('Bitte wähle eine gültige WebVTT-Datei mit der Endung .vtt.');
        }

        $reportedSize = $upload->getSize();
        if (false === $reportedSize || $reportedSize < 1 || $reportedSize > self::MAX_FILE_BYTES) {
            throw new \InvalidArgumentException('Untertiteldateien müssen zwischen 1 Byte und 1 MiB groß sein.');
        }

        $contents = file_get_contents(
            $upload->getPathname(),
            false,
            null,
            0,
            self::MAX_FILE_BYTES + 1,
        );
        if (false === $contents) {
            throw new \InvalidArgumentException('Die Untertiteldatei konnte nicht sicher gelesen werden.');
        }

        if (strlen($contents) !== $reportedSize) {
            throw new \InvalidArgumentException('Die Untertiteldatei ist leer, zu groß oder wurde während der Prüfung verändert.');
        }

        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->buffer($contents);
        if (!is_string($mimeType) || !in_array($mimeType, ['text/plain', 'text/vtt', 'application/octet-stream'], true)) {
            throw new \InvalidArgumentException('Die Datei enthält kein unterstütztes WebVTT-Format.');
        }

        $contents = self::validateAndNormalize($contents);
        $objectId = bin2hex(random_bytes(32));
        $this->storage->write($objectId, $contents);

        return [
            'object_id' => $objectId,
            'size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
        ];
    }

    public function delete(string $objectId): void
    {
        if (1 !== preg_match('/\A[a-f0-9]{64}\z/D', $objectId)) {
            throw new \InvalidArgumentException('Die Kennung der privaten Untertiteldatei ist ungültig.');
        }

        $this->storage->delete($objectId);
    }

    private static function validateAndNormalize(string $contents): string
    {
        if (!mb_check_encoding($contents, 'UTF-8')
            || str_starts_with($contents, "\xEF\xBB\xBF")
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contents)
        ) {
            throw new \InvalidArgumentException('Die WebVTT-Datei enthält ungültige Zeichen.');
        }

        $contents = str_replace("\r\n", "\n", $contents);
        if (str_contains($contents, "\r")) {
            throw new \InvalidArgumentException('Die WebVTT-Datei enthält ungültige Zeilenumbrüche.');
        }

        $lines = explode("\n", $contents);
        if (count($lines) > 20000
            || 1 !== preg_match('/\AWEBVTT(?:[ \t][^\r\n]{0,120})?\z/D', $lines[0])
        ) {
            throw new \InvalidArgumentException('Die Datei besitzt keinen gültigen WebVTT-Kopf.');
        }

        $index = 1;
        $lineCount = count($lines);
        while ($index < $lineCount && '' !== $lines[$index]) {
            if (mb_strlen($lines[$index]) > 240
                || 1 !== preg_match('/\A[A-Za-z][A-Za-z0-9-]{0,39}: [^<>\x00-\x1F]{0,180}\z/u', $lines[$index])
            ) {
                throw new \InvalidArgumentException('Die WebVTT-Kopfdaten sind ungültig.');
            }
            ++$index;
        }

        $cueCount = 0;
        while ($index < $lineCount) {
            while ($index < $lineCount && '' === $lines[$index]) {
                ++$index;
            }
            if ($index >= $lineCount) {
                break;
            }

            if ('NOTE' === $lines[$index] || str_starts_with($lines[$index], 'NOTE ')) {
                ++$index;
                while ($index < $lineCount && '' !== $lines[$index]) {
                    if (mb_strlen($lines[$index]) > 500 || 1 === preg_match('/[<>\x00-\x1F]/u', $lines[$index])) {
                        throw new \InvalidArgumentException('Ein WebVTT-Kommentar enthält ungültige Zeichen oder ist zu lang.');
                    }
                    ++$index;
                }
                continue;
            }

            if ('STYLE' === $lines[$index] || 'REGION' === $lines[$index]) {
                throw new \InvalidArgumentException('WebVTT-Stil- und Regionsblöcke werden nicht unterstützt.');
            }

            if (!self::isTimingLine($lines[$index])) {
                if (mb_strlen($lines[$index]) > 128 || 1 === preg_match('/[<>\x00-\x1F]/u', $lines[$index])) {
                    throw new \InvalidArgumentException('Die WebVTT-Kennzeichnung ist ungültig.');
                }
                ++$index;
            }

            $timingLine = $lines[$index] ?? '';
            if (!self::isTimingLine($timingLine)) {
                throw new \InvalidArgumentException('Ein WebVTT-Untertitel besitzt keine gültigen Zeitangaben.');
            }
            self::validateTiming($timingLine);
            ++$index;

            $cueLines = 0;
            $cueBytes = 0;
            while ($index < $lineCount && '' !== $lines[$index]) {
                $cueLine = $lines[$index];
                if (mb_strlen($cueLine) > 500 || 1 === preg_match('/[<>\x00-\x1F]/u', $cueLine)) {
                    throw new \InvalidArgumentException('Ein WebVTT-Untertitel enthält ungültige oder ausführbare Markierungen.');
                }
                $cueBytes += strlen($cueLine);
                ++$cueLines;
                ++$index;
            }
            if (0 === $cueLines || $cueBytes > 2000) {
                throw new \InvalidArgumentException('Ein WebVTT-Untertitel ist leer oder zu lang.');
            }

            ++$cueCount;
        }

        if (0 === $cueCount) {
            throw new \InvalidArgumentException('Die WebVTT-Datei enthält keine Untertitel.');
        }

        return rtrim(implode("\n", $lines), "\n")."\n";
    }

    private static function isTimingLine(string $line): bool
    {
        return 1 === preg_match(
            '/\A(?:(\d{2,}):)?([0-5]\d):([0-5]\d)\.(\d{3}) --> (?:(\d{2,}):)?([0-5]\d):([0-5]\d)\.(\d{3})(?: [A-Za-z0-9.,:%=+_ -]{1,200})?\z/D',
            $line,
        );
    }

    private static function validateTiming(string $line): void
    {
        if (1 !== preg_match(
            '/\A(?:(\d{2,}):)?([0-5]\d):([0-5]\d)\.(\d{3}) --> (?:(\d{2,}):)?([0-5]\d):([0-5]\d)\.(\d{3})/',
            $line,
            $matches,
        )) {
            throw new \InvalidArgumentException('Ein WebVTT-Untertitel besitzt keine gültigen Zeitangaben.');
        }

        $startHours = '' === $matches[1] ? 0 : (int) $matches[1];
        $endHours = '' === $matches[5] ? 0 : (int) $matches[5];
        $start = ($startHours * 3600000)
            + ((int) $matches[2] * 60000)
            + ((int) $matches[3] * 1000)
            + (int) $matches[4];
        $end = ($endHours * 3600000)
            + ((int) $matches[6] * 60000)
            + ((int) $matches[7] * 1000)
            + (int) $matches[8];

        if ($end <= $start) {
            throw new \InvalidArgumentException('Die WebVTT-Endzeit muss nach der Startzeit liegen.');
        }
    }
}
