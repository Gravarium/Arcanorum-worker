<?php

declare(strict_types=1);

namespace App\Tests\Module\Scheduling\Support;

use App\Module\Scheduling\Application\SchedulingAccessDenied;
use App\Module\Scheduling\Application\SchedulingAdminGuard;

final class RecordingSchedulingAdminGuard implements SchedulingAdminGuard
{
    public function __construct(public bool $mayManage = true, public string $validToken = 'safe-test-token')
    {
    }

    public function assertCanManageScheduling(): void
    {
        if (!$this->mayManage) {
            throw new SchedulingAccessDenied('Forbidden.');
        }
    }

    public function assertValidCsrfToken(string $token): void
    {
        if (!hash_equals($this->validToken, $token)) {
            throw new SchedulingAccessDenied('Forbidden.');
        }
    }
}
