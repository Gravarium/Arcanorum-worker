<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Platform\Security\AdminSessionLifetime;
use PHPUnit\Framework\TestCase;

final class AdminSessionLifetimeTest extends TestCase
{
    public function testIdleTimeoutExpiresAtTheExactBoundary(): void
    {
        $lifetime = new AdminSessionLifetime();

        self::assertFalse($lifetime->isExpired(1000, 5000, 6799));
        self::assertTrue($lifetime->isExpired(1000, 5000, 6800));
    }

    public function testAbsoluteTimeoutExpiresAtTheExactBoundary(): void
    {
        $lifetime = new AdminSessionLifetime();

        self::assertFalse($lifetime->isExpired(1000, 29700, 29799));
        self::assertTrue($lifetime->isExpired(1000, 29700, 29800));
    }

    public function testActivityCannotPrecedeAuthenticationTimestamp(): void
    {
        $lifetime = new AdminSessionLifetime();

        self::assertFalse($lifetime->isExpired(1000, 1000, 1001));
        self::assertTrue($lifetime->isExpired(1000, 999, 1001));
    }

    public function testMissingNonPositiveOrFutureTimestampsExpireFailClosed(): void
    {
        $lifetime = new AdminSessionLifetime();

        self::assertTrue($lifetime->isExpired(null, 2000, 2001));
        self::assertTrue($lifetime->isExpired(1000, null, 2001));
        self::assertTrue($lifetime->isExpired(0, 2000, 2001));
        self::assertTrue($lifetime->isExpired(1001, 1000, 1000));
        self::assertTrue($lifetime->isExpired(1000, 1001, 1000));
    }
}
