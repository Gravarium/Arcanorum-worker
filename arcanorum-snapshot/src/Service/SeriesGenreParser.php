<?php

declare(strict_types=1);

namespace App\Service;

final class SeriesGenreParser
{
    private const int MAX_INPUT_LENGTH = 600;
    private const int MAX_GENRES = 12;
    private const int MAX_NAME_LENGTH = 40;

    /**
     * @return list<string>
     */
    public static function parseNames(string $input): array
    {
        if (1 !== preg_match('//u', $input)) {
            throw new \InvalidArgumentException('Die Genre-Liste muss gültiges UTF-8 enthalten.');
        }
        if (mb_strlen($input) > self::MAX_INPUT_LENGTH) {
            throw new \InvalidArgumentException('Die Genre-Liste darf höchstens 600 Zeichen lang sein.');
        }
        if (1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $input)) {
            throw new \InvalidArgumentException('Die Genre-Liste enthält unzulässige Steuerzeichen.');
        }

        $parts = preg_split('/[,;\r\n]+/u', $input);
        if (false === $parts) {
            throw new \InvalidArgumentException('Die Genre-Liste muss gültiges UTF-8 enthalten.');
        }
        $genres = [];
        foreach ($parts as $part) {
            $genre = trim((string) preg_replace('/\s+/u', ' ', trim($part)));
            if ('' === $genre) {
                continue;
            }
            if (mb_strlen($genre) > self::MAX_NAME_LENGTH) {
                throw new \InvalidArgumentException('Ein Genre darf höchstens 40 Zeichen lang sein.');
            }

            $normalized = mb_strtolower($genre, 'UTF-8');
            if (isset($genres[$normalized])) {
                continue;
            }
            if (count($genres) >= self::MAX_GENRES) {
                throw new \InvalidArgumentException('Pro Serie sind höchstens 12 Genres erlaubt.');
            }

            $genres[$normalized] = $genre;
        }

        return array_values($genres);
    }
}
