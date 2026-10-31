<?php

declare(strict_types=1);

namespace App\Module\Advertising\Domain;

enum AdStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Inactive = 'inactive';
    case Archived = 'archived';
}
