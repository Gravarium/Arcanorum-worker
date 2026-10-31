<?php

declare(strict_types=1);

namespace App\Module\Comment;

use Symfony\Component\HttpFoundation\Request;

final class CommentPagination
{
    public static function pageCount(int $commentCount, int $commentsPerPage): int
    {
        if ($commentCount <= 0) {
            return 1;
        }

        $pageSize = max(1, min(100, $commentsPerPage));

        return intdiv($commentCount, $pageSize) + (0 === $commentCount % $pageSize ? 0 : 1);
    }

    public static function selectedPage(Request $request, int $pageCount): int
    {
        return max(1, min($pageCount, self::pageParameter($request) ?? 1));
    }

    public static function pageParameter(Request $request): ?int
    {
        $value = $request->query->all()['comment_page'] ?? null;
        $maximumValue = (string) PHP_INT_MAX;
        if (!is_string($value)
            || '' === $value
            || !ctype_digit($value)
            || strlen($value) > strlen($maximumValue)
            || (strlen($value) === strlen($maximumValue) && strcmp($value, $maximumValue) > 0)
        ) {
            return null;
        }

        $page = (int) $value;

        return $page > 1 ? $page : null;
    }
}
