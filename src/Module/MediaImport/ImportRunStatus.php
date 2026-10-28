<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

enum ImportRunStatus: string
{
    case NotClaimed = 'not_claimed';
    case Progressed = 'progressed';
    case Completed = 'completed';
    case Deferred = 'deferred';
    case Failed = 'failed';
    case LeaseLost = 'lease_lost';
}
