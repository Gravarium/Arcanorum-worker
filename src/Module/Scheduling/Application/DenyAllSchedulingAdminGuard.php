<?php

declare(strict_types=1);

namespace App\Module\Scheduling\Application;

final class DenyAllSchedulingAdminGuard implements SchedulingAdminGuard
{
    public function assertCanManageScheduling(): void
    {
        throw new SchedulingAccessDenied('Scheduling administration is not configured.');
    }

    public function assertValidCsrfToken(string $token): void
    {
        throw new SchedulingAccessDenied('Scheduling administration is not configured.');
    }
}
