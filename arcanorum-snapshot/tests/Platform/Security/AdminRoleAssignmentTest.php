<?php

declare(strict_types=1);

namespace App\Tests\Platform\Security;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use PHPUnit\Framework\TestCase;

final class AdminRoleAssignmentTest extends TestCase
{
    public function testKnownRolesAreNormalizedAndUnknownRolesAreRejected(): void
    {
        $user = new AdminUser('moderator@example.test');
        $user->setRoles(['ROLE_USER', AdminRole::VideoModerator->value, AdminRole::VideoModerator->value]);

        self::assertSame([AdminRole::VideoModerator->value, 'ROLE_USER'], $user->getRoles());

        $rejectedRoles = [];
        foreach (['ROLE_UNKNOWN', 'ROLE_ADMIN_STAFF'] as $unknownRole) {
            try {
                $user->setRoles([$unknownRole]);
            } catch (\InvalidArgumentException) {
                $rejectedRoles[] = $unknownRole;
            }
        }

        self::assertSame(['ROLE_UNKNOWN', 'ROLE_ADMIN_STAFF'], $rejectedRoles);
    }

    public function testAdministratorCannotBeCombinedWithModeratorRoles(): void
    {
        $user = new AdminUser('administrator@example.test');

        $this->expectException(\InvalidArgumentException::class);
        $user->setRoles([AdminRole::Administrator->value, AdminRole::VideoModerator->value]);
    }

    public function testUnexpectedPersistedRolesFailClosed(): void
    {
        $user = new AdminUser('corrupted-roles@example.test');
        $roles = new \ReflectionProperty(AdminUser::class, 'roles');
        $roles->setValue($user, ['ROLE_ADMIN_STAFF', 'ROLE_UNKNOWN', null]);

        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertFalse(AdminRole::hasStaffAccess($user->getRoles()));
    }

    public function testAnyUnexpectedDuplicateOrConflictingPersistedRoleFailsClosed(): void
    {
        $user = new AdminUser('conflicting-roles@example.test');
        $roles = new \ReflectionProperty(AdminUser::class, 'roles');

        foreach ([
            [AdminRole::VideoModerator->value, 'ROLE_UNKNOWN'],
            [AdminRole::VideoModerator->value, AdminRole::VideoModerator->value],
            [AdminRole::Administrator->value, AdminRole::CommentModerator->value],
        ] as $invalidRoles) {
            $roles->setValue($user, $invalidRoles);

            self::assertSame(['ROLE_USER'], $user->getRoles());
            self::assertFalse(AdminRole::hasStaffAccess($user->getRoles()));
            self::assertFalse(AdminRole::canModerateVideos($user->getRoles()));
            self::assertFalse(AdminRole::canModerateComments($user->getRoles()));
        }
    }

    public function testModerationPermissionsRemainScopedByRole(): void
    {
        self::assertTrue(AdminRole::canModerateVideos([AdminRole::VideoModerator->value, 'ROLE_USER']));
        self::assertFalse(AdminRole::canModerateComments([AdminRole::VideoModerator->value, 'ROLE_USER']));
        self::assertTrue(AdminRole::canModerateComments([AdminRole::CommentModerator->value, 'ROLE_USER']));
        self::assertFalse(AdminRole::canModerateVideos([AdminRole::CommentModerator->value, 'ROLE_USER']));
        self::assertTrue(AdminRole::canModerateVideos([AdminRole::Administrator->value, 'ROLE_USER']));
        self::assertTrue(AdminRole::canModerateComments([AdminRole::Administrator->value, 'ROLE_USER']));
    }
}
