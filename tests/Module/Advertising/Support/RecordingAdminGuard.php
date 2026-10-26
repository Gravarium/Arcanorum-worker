<?php

declare(strict_types=1);

namespace App\Tests\Module\Advertising\Support;

use App\Module\Advertising\Application\AdvertisingAccessDenied;
use App\Module\Advertising\Application\AdvertisingAdminGuard;

final class RecordingAdminGuard implements AdvertisingAdminGuard
{
    public function __construct(public bool $mayManage = true, public string $validToken = 'safe-test-token')
    {
    }

    public function assertCanManageAdvertising(): void
    {
        if (!$this->mayManage) {
            throw new AdvertisingAccessDenied('Forbidden.');
        }
    }

    public function assertValidCsrfToken(string $token): void
    {
        if (!hash_equals($this->validToken, $token)) {
            throw new AdvertisingAccessDenied('Forbidden.');
        }
    }
}
