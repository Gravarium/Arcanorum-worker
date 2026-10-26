<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

interface SchedulingAdminGuard
{
    public function assertCanManageScheduling(): void;

    public function assertValidCsrfToken(string $token): void;
}
