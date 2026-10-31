<?php

declare(strict_types=1);

namespace App\Module\News\Service;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\MemberAccount;
use App\Entity\NewsArticle;
use Symfony\Component\Security\Core\User\UserInterface;

final class NewsArticleAccessPolicy
{
    public function canReadMembersOnly(?UserInterface $user): bool
    {
        if ($user instanceof AdminUser) {
            return in_array(AdminRole::Administrator->value, $user->getRoles(), true);
        }

        return $user instanceof MemberAccount
            && $user->isActive()
            && !$user->isBanned()
            && $user->isEmailVerified();
    }

    public function canRead(NewsArticle $article, ?UserInterface $user): bool
    {
        return !$article->isMembersOnly() || $this->canReadMembersOnly($user);
    }
}
