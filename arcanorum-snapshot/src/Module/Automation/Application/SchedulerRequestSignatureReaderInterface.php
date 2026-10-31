<?php

declare(strict_types=1);

namespace App\Module\Automation\Application;

interface SchedulerRequestSignatureReaderInterface
{
    /** @param resource $stream */
    public function read($stream): ?string;
}
