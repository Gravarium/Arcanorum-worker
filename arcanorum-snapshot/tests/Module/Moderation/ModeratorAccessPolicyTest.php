<?php

declare(strict_types=1);

namespace App\Tests\Module\Moderation;

use App\Module\Moderation\ModeratorAccessPolicy;
use App\Module\Moderation\ModeratorPermission;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModeratorAccessPolicyTest extends TestCase
{
    /** @param list<string> $roles */
    #[DataProvider('permissionCases')]
    public function testEachKnownRoleGetsOnlyItsAssignedModerationRights(
        array $roles,
        ModeratorPermission $permission,
        bool $expected,
    ): void {
        self::assertSame($expected, new ModeratorAccessPolicy()->allows($roles, $permission));
    }

    /** @return iterable<string, array{list<string>, ModeratorPermission, bool}> */
    public static function permissionCases(): iterable
    {
        yield 'administrator moderates videos' => [['ROLE_ADMIN'], ModeratorPermission::ModerateVideos, true];
        yield 'administrator moderates comments' => [['ROLE_ADMIN'], ModeratorPermission::ModerateComments, true];
        yield 'general moderator moderates videos' => [['ROLE_MODERATOR'], ModeratorPermission::ModerateVideos, true];
        yield 'general moderator moderates comments' => [['ROLE_MODERATOR'], ModeratorPermission::ModerateComments, true];
        yield 'video moderator moderates videos' => [['ROLE_MODERATOR_VIDEO'], ModeratorPermission::ModerateVideos, true];
        yield 'video moderator cannot moderate comments' => [['ROLE_MODERATOR_VIDEO'], ModeratorPermission::ModerateComments, false];
        yield 'comment moderator cannot moderate videos' => [['ROLE_MODERATOR_COMMENTS'], ModeratorPermission::ModerateVideos, false];
        yield 'comment moderator moderates comments' => [['ROLE_MODERATOR_COMMENTS'], ModeratorPermission::ModerateComments, true];
        yield 'member has no video rights' => [['ROLE_USER'], ModeratorPermission::ModerateVideos, false];
        yield 'member has no comment rights' => [['ROLE_USER'], ModeratorPermission::ModerateComments, false];
        yield 'empty role list denies access' => [[], ModeratorPermission::ModerateVideos, false];
    }

    public function testUnknownOrMalformedRoleDataDeniesAccessEvenWhenAValidRoleIsAlsoPresent(): void
    {
        $policy = new ModeratorAccessPolicy();

        self::assertFalse($policy->allows(['ROLE_MODERATOR_VIDEO', 'ROLE_SUPERUSER'], ModeratorPermission::ModerateVideos));
        self::assertFalse($policy->allows(['ROLE_ADMIN' => 'ROLE_ADMIN'], ModeratorPermission::ModerateVideos));
        self::assertFalse($policy->allows(['ROLE_MODERATOR_VIDEO', 12], ModeratorPermission::ModerateVideos));
        self::assertFalse($policy->allows(['ROLE_ADMIN', 'ROLE_MODERATOR_VIDEO'], ModeratorPermission::ModerateVideos));
        self::assertFalse($policy->allows(['ROLE_ADMIN', 'ROLE_ADMIN'], ModeratorPermission::ModerateVideos));
        self::assertFalse($policy->allows('ROLE_ADMIN', ModeratorPermission::ModerateVideos));
    }

    public function testOnlyTheExactAdministratorRoleCanManageAssignments(): void
    {
        $policy = new ModeratorAccessPolicy();

        self::assertTrue($policy->isAdministrator(['ROLE_ADMIN', 'ROLE_USER']));
        self::assertFalse($policy->isAdministrator(['ROLE_MODERATOR']));
        self::assertFalse($policy->isAdministrator(['ROLE_ADMIN', 'ROLE_SUPERUSER']));
    }
}
