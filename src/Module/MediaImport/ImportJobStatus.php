<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

enum ImportJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
