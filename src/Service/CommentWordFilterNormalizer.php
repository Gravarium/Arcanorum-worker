<?php

declare(strict_types=1);

namespace App\Service;

final class CommentWordFilterNormalizer
{
    public const int MAX_TERM_LENGTH = 100;

    /**
     * @return array{term: string, normalized: string}
     */
    public static function parseTerm(string $input): array
    {
        self::assertValidText($input);

        $term = trim((string) preg_replace('/\s+/u', ' ', $input));
        if ('' === $term) {
            throw new \InvalidArgumentException('Das Filterwort darf nicht leer sein.');
        }
        if (mb_strlen($term, 'UTF-8') > self::MAX_TERM_LENGTH) {
            throw new \InvalidArgumentException('Ein Filterwort oder eine Wortgruppe darf höchstens 100 Zeichen lang sein.');
        }

        return [
            'term' => $term,
            'normalized' => mb_strtolower($term, 'UTF-8'),
        ];
    }

    public static function normalizeText(string $text): string
    {
        self::assertValidText($text);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)), 'UTF-8');
    }

    private static function assertValidText(string $text): void
    {
        if (1 !== preg_match('//u', $text)) {
            throw new \InvalidArgumentException('Der Text muss gültiges UTF-8 enthalten.');
        }
        if (1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text)) {
            throw new \InvalidArgumentException('Der Text enthält unzulässige Steuerzeichen.');
        }
    }
}
