<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AdminUser;
use App\Entity\MemberAccount;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

final class SecurityUserInterfaceContractTest extends TestCase
{
    public function testAdminUserMarksItsEmptyCredentialErasureHookAsDeprecated(): void
    {
        $user = new AdminUser('admin@example.test');

        self::assertInstanceOf(UserInterface::class, $user);
        self::assertCount(1, new \ReflectionMethod($user, 'eraseCredentials')->getAttributes(\Deprecated::class));
    }

    public function testMemberAccountMarksItsEmptyCredentialErasureHookAsDeprecated(): void
    {
        $user = new MemberAccount('member@example.test', 'member-user', 'Member User');

        self::assertInstanceOf(UserInterface::class, $user);
        self::assertCount(1, new \ReflectionMethod($user, 'eraseCredentials')->getAttributes(\Deprecated::class));
    }
}
