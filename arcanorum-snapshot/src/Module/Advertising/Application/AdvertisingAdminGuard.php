<?php

declare(strict_types=1);

namespace App\Module\Advertising\Application;

interface AdvertisingAdminGuard
{
    public function assertCanManageAdvertising(): void;

    public function assertValidCsrfToken(string $token): void;
}
