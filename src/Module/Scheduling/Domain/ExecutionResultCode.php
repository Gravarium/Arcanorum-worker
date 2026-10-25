<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Domain;

enum ExecutionResultCode: string
{
    case Completed = 'completed';
    case HandlerFailed = 'handler_failed';
    case HandlerUnavailable = 'handler_unavailable';
    case AttemptLimit = 'attempt_limit';
    case Superseded = 'superseded';
}
