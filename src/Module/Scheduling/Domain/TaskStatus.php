<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

enum TaskStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Disabled = 'disabled';
}
