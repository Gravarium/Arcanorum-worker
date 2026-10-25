<?php

declare(strict_types=1);

namespace App\Platform\Security;

final class AdminAreaRequestPath
{
    public static function decode(string $rawPath): string
    {
        return rawurldecode($rawPath);
    }

    public static function isAdminArea(string $decodedPath): bool
    {
        return '/admin' === $decodedPath || str_starts_with($decodedPath, '/admin/');
    }
}
