<?php

declare(strict_types=1);

namespace App\Tests\Module\News;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Entity\MemberAccount;
use App\Entity\NewsArticle;
use App\Entity\PublishingStatus;
use App\Module\News\Service\NewsArticleAccessPolicy;
use PHPUnit\Framework\TestCase;

final class NewsArticleAccessPolicyTest extends TestCase
{
    public function testMembersOnlyContentRequiresAnActiveVerifiedMemberOrAdministrator(): void
    {
        $article = new NewsArticle(
            'Private news',
            'private-news',
            null,
            null,
            'Private body',
            PublishingStatus::Published,
            false,
            true,
            'Editorial Desk',
            true,
        );
        $policy = new NewsArticleAccessPolicy();
        $verifiedMember = new MemberAccount('verified@example.test', 'verified', 'Verified Member');
        $unverifiedMember = new MemberAccount('unverified@example.test', 'unverified', 'Unverified Member');
        $unverifiedMember->requireEmailVerification();
        $inactiveMember = new MemberAccount('inactive@example.test', 'inactive', 'Inactive Member');
        $inactiveMember->setActive(false);

        self::assertFalse($policy->canRead($article, null));
        self::assertFalse($policy->canRead($article, $unverifiedMember));
        self::assertFalse($policy->canRead($article, $inactiveMember));
        self::assertTrue($policy->canRead($article, $verifiedMember));
        $administrator = new AdminUser('admin@example.test');
        $administrator->setRoles([AdminRole::Administrator->value]);
        self::assertTrue($policy->canRead($article, $administrator));
        $moderator = new AdminUser('moderator@example.test');
        $moderator->setRoles([AdminRole::Moderator->value]);
        self::assertFalse($policy->canRead($article, $moderator));
    }

    public function testPublicNewsRemainsReadableToVisitors(): void
    {
        $article = new NewsArticle(
            'Public news',
            'public-news',
            null,
            null,
            'Public body',
            PublishingStatus::Published,
            false,
            true,
        );

        self::assertTrue(new NewsArticleAccessPolicy()->canRead($article, null));
    }
}
