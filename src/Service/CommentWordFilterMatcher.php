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

    private static function isWordCharacter(string $character): bool
    {
        return 1 === preg_match('/[\p{L}\p{M}\p{N}_]/u', $character);
    }
}
