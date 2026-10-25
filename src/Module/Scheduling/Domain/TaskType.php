<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

enum TaskType: string
{
    case CacheCleanup = 'cache_cleanup';
    case PublicIndexRefresh = 'public_index_refresh';
}
