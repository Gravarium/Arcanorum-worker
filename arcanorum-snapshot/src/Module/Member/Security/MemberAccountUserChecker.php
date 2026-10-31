<?php

declare(strict_types=1);

namespace App\Module\Member\Security;

use App\Entity\MemberAccount;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class MemberAccountUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        $this->assertActive($user);
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        $this->assertActive($user);
    }

    private function assertActive(UserInterface $user): void
    {
        if ($user instanceof MemberAccount && (!$user->isActive() || !$user->isEmailVerified())) {
            throw new DisabledException();
        }
    }
}
