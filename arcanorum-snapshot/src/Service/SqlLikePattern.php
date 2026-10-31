<?php

declare(strict_types=1);

namespace App\Service;

final class SqlLikePattern
{
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
