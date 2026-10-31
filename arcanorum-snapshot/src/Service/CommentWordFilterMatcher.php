<?php

declare(strict_types=1);

namespace App\Service;

final class CommentWordFilterMatcher
{
    public static function matchesNormalizedText(string $normalizedText, string $normalizedTerm): bool
    {
        if ('' === $normalizedText || '' === $normalizedTerm) {
            return false;
        }

        $textLength = mb_strlen($normalizedText, 'UTF-8');
        $termLength = mb_strlen($normalizedTerm, 'UTF-8');
        $offset = 0;

        while (false !== ($position = mb_strpos($normalizedText, $normalizedTerm, $offset, 'UTF-8'))) {
            $before = $position > 0 ? mb_substr($normalizedText, $position - 1, 1, 'UTF-8') : '';
            $afterOffset = $position + $termLength;
            $after = $afterOffset < $textLength ? mb_substr($normalizedText, $afterOffset, 1, 'UTF-8') : '';
            $first = mb_substr($normalizedTerm, 0, 1, 'UTF-8');
            $last = mb_substr($normalizedTerm, -1, 1, 'UTF-8');

            $leftBoundary = !self::isWordCharacter($first) || '' === $before || !self::isWordCharacter($before);
            $rightBoundary = !self::isWordCharacter($last) || '' === $after || !self::isWordCharacter($after);
            if ($leftBoundary && $rightBoundary) {
                return true;
            }

            $offset = $position + 1;
        }

        return false;
    }

    public static function replaceOccurrences(
        string $text,
        string $normalizedTerm,
        string $replacement = '***',
    ): string {
        if ('' === $text || '' === $normalizedTerm) {
            return $text;
        }

        $normalizedTerm = CommentWordFilterNormalizer::parseTerm($normalizedTerm)['normalized'];

        $termCharacters = preg_split('//u', $normalizedTerm, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($termCharacters) || [] === $termCharacters) {
            throw new \InvalidArgumentException('Das normalisierte Filterwort ist ungültig.');
        }

        $first = $termCharacters[0];
        $last = array_last($termCharacters);
        $leftBoundary = self::isWordCharacter($first) ? '(?<![\p{L}\p{M}\p{N}_])' : '';
        $rightBoundary = self::isWordCharacter($last) ? '(?![\p{L}\p{M}\p{N}_])' : '';
        $words = explode(' ', $normalizedTerm);
        $quotedWords = array_map(static fn (string $word): string => preg_quote($word, '~'), $words);
        $pattern = '~'.$leftBoundary.implode('\\s+', $quotedWords).$rightBoundary.'~iu';
        $replaced = preg_replace($pattern, $replacement, $text);
        if (!is_string($replaced)) {
            throw new \RuntimeException('Der Kommentarfilter konnte den Text nicht sicher ersetzen.');
        }

        return $replaced;
    }

    private static function isWordCharacter(string $character): bool
    {
        return 1 === preg_match('/[\p{L}\p{M}\p{N}_]/u', $character);
    }
}
