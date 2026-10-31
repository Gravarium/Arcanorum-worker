<?php

declare(strict_types=1);

namespace App\Entity;

enum ScheduledTaskRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
