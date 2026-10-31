<?php

declare(strict_types=1);

namespace App\Module\Advertising\Application;

final class DenyAllAdvertisingAdminGuard implements AdvertisingAdminGuard
{
    public function assertCanManageAdvertising(): void
    {
        throw new AdvertisingAccessDenied('Advertising administration is not configured.');
    }

    public function assertValidCsrfToken(string $token): void
    {
        throw new AdvertisingAccessDenied('Advertising administration is not configured.');
    }
}
