<?php

declare(strict_types=1);

namespace App\Module\MediaImport;

interface ImportJobControlPolicy
{
    public function mayPause(string $actorId, string $jobId): bool;

    public function mayResume(string $actorId, string $jobId): bool;

    public function mayCancel(string $actorId, string $jobId): bool;
}
