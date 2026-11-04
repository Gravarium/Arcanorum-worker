<?php

declare(strict_types=1);

namespace App\Module\Video\Service;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\MemberAccount;
use App\Entity\Video;
use Symfony\Component\Security\Core\User\UserInterface;

final class VideoAccessPolicy
{
    public function canWatch(Video $video, ?UserInterface $user): bool
    {
        if (!$video->isMembersOnly()) {
            return true;
        }

        if ($user instanceof AdminUser) {
            return in_array(AdminRole::Administrator->value, $user->getRoles(), true);
        }

        return $user instanceof MemberAccount
            && $user->isActive()
            && !$user->isBanned()
            && $user->isEmailVerified();
    }
}
