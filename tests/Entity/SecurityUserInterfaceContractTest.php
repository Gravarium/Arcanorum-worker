<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AdminUser;
use App\Entity\MemberAccount;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

final class SecurityUserInterfaceContractTest extends TestCase
{
    public function testAdminUserSatisfiesSymfonyCredentialErasureContract(): void
    {
        $user = new AdminUser('admin@example.test');

        self::assertInstanceOf(UserInterface::class, $user);
        $user->eraseCredentials();
    }

    public function testMemberAccountSatisfiesSymfonyCredentialErasureContract(): void
    {
        $user = new MemberAccount('member@example.test', 'member-user', 'Member User');

        self::assertInstanceOf(UserInterface::class, $user);
        $user->eraseCredentials();
    }
}
