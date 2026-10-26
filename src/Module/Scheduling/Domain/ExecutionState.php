<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

enum ExecutionState: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Abandoned = 'abandoned';
}
