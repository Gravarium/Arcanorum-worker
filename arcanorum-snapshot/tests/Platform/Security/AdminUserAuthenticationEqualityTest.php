<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Entity\AdminUser;
use PHPUnit\Framework\TestCase;

final class AdminUserAuthenticationEqualityTest extends TestCase
{
    public function testDifferentAccountIdInvalidatesSessionEvenWhenLoginAttributesMatch(): void
    {
        $sessionUser = new AdminUser('admin@example.test');
        $sessionUser->setPassword('same-password-hash');
        $sessionUser->setRoles(['ROLE_ADMIN']);

        $recreatedUser = new AdminUser('admin@example.test');
        $recreatedUser->setPassword('same-password-hash');
        $recreatedUser->setRoles(['ROLE_ADMIN']);

        self::assertNotSame($sessionUser->getId(), $recreatedUser->getId());
        self::assertFalse($sessionUser->isEqualTo($recreatedUser));
    }

    public function testPasswordStateChangeInvalidatesEqualityInBothDirections(): void
    {
        $sessionUser = new AdminUser('admin@example.test');
        $refreshedUser = clone $sessionUser;
        $refreshedUser->setPassword('new-password-hash');

        self::assertSame($sessionUser->getId(), $refreshedUser->getId());
        self::assertFalse($sessionUser->isEqualTo($refreshedUser));
        self::assertFalse($refreshedUser->isEqualTo($sessionUser));
    }

    public function testLegacyCrc32cDigestRemainsCompatibleWithRehashedPassword(): void
    {
        $sessionUser = new AdminUser('admin@example.test');
        $sessionUser->setRoles(['ROLE_ADMIN']);
        $refreshedUser = clone $sessionUser;
        $modernPasswordHash = '$argon2id$v=19$m=65536,t=4,p=1$test-salt$test-digest';
        $legacyPasswordDigest = hash('crc32c', $modernPasswordHash);

        $sessionUser->setPassword($legacyPasswordDigest);
        $refreshedUser->setPassword($modernPasswordHash);

        self::assertSame(8, strlen($legacyPasswordDigest));
        self::assertTrue($sessionUser->isEqualTo($refreshedUser));

        $unrelatedUser = clone $refreshedUser;
        $unrelatedPasswordHash = $modernPasswordHash.'-unrelated';
        self::assertNotSame($legacyPasswordDigest, hash('crc32c', $unrelatedPasswordHash));
        $unrelatedUser->setPassword($unrelatedPasswordHash);

        self::assertFalse($sessionUser->isEqualTo($unrelatedUser));
    }

    public function testUnchangedAccountRemainsEqualToItsRefreshedCopy(): void
    {
        $sessionUser = new AdminUser('admin@example.test');
        $sessionUser->setPassword('stable-password-hash');
        $sessionUser->setRoles(['ROLE_ADMIN']);

        self::assertTrue($sessionUser->isEqualTo(clone $sessionUser));
    }
}
