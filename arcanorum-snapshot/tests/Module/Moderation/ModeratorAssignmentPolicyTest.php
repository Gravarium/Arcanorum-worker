<?php

declare(strict_types=1);

namespace App\Tests\Module\Moderation;

use App\Module\Moderation\ModeratorAccessPolicy;
use App\Module\Moderation\ModeratorAssignmentDecision;
use App\Module\Moderation\ModeratorAssignmentPolicy;
use App\Module\Moderation\ModeratorRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModeratorAssignmentPolicyTest extends TestCase
{
    private const string ACTOR_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string TARGET_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    #[DataProvider('assignmentCases')]
    public function testRoleAssignmentRequiresTheCompleteTrustedContext(
        mixed $actorRoles,
        string $actorId,
        string $targetId,
        ?ModeratorRole $currentRole,
        ?ModeratorRole $requestedRole,
        bool $actorMfaConfigured,
        bool $targetMfaConfigured,
        bool $csrfVerified,
        int $administratorCount,
        ModeratorAssignmentDecision $expected,
    ): void {
        $policy = new ModeratorAssignmentPolicy(new ModeratorAccessPolicy());

        self::assertSame(
            $expected,
            $policy->decide(
                $actorRoles,
                $actorId,
                $targetId,
                $currentRole,
                $requestedRole,
                $actorMfaConfigured,
                $targetMfaConfigured,
                $csrfVerified,
                $administratorCount,
            ),
        );
    }

    /** @return iterable<string, array{mixed, string, string, ?ModeratorRole, ?ModeratorRole, bool, bool, bool, int, ModeratorAssignmentDecision}> */
    public static function assignmentCases(): iterable
    {
        yield 'admin can grant after verified safeguards' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, self::TARGET_ID, null, ModeratorRole::VideoModerator, true, true, true, 1,
            ModeratorAssignmentDecision::Allowed,
        ];
        yield 'non-admin cannot assign roles' => [
            ['ROLE_MODERATOR'], self::ACTOR_ID, self::TARGET_ID, null, ModeratorRole::VideoModerator, true, true, true, 1,
            ModeratorAssignmentDecision::ActorDenied,
        ];
        yield 'unknown role data cannot grant assignment rights' => [
            ['ROLE_ADMIN', 'ROLE_UNKNOWN'], self::ACTOR_ID, self::TARGET_ID, null, ModeratorRole::VideoModerator, true, true, true, 1,
            ModeratorAssignmentDecision::ActorDenied,
        ];
        yield 'invalid csrf is rejected before mutation' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, self::TARGET_ID, null, ModeratorRole::VideoModerator, true, true, false, 1,
            ModeratorAssignmentDecision::CsrfRequired,
        ];
        yield 'actor without mfa cannot assign' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, self::TARGET_ID, null, ModeratorRole::VideoModerator, false, true, true, 1,
            ModeratorAssignmentDecision::ActorMfaRequired,
        ];
        yield 'invalid target identity is rejected' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, 'not-an-id', null, ModeratorRole::VideoModerator, true, true, true, 1,
            ModeratorAssignmentDecision::InvalidIdentity,
        ];
        yield 'actor cannot change own role' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, self::ACTOR_ID, ModeratorRole::Administrator, null, true, true, true, 1,
            ModeratorAssignmentDecision::SelfChangeForbidden,
        ];
        yield 'target needs mfa before staff access is granted' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, self::TARGET_ID, null, ModeratorRole::CommentModerator, true, false, true, 1,
            ModeratorAssignmentDecision::TargetMfaRequired,
        ];
        yield 'baseline member role cannot be assigned as a staff role' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, self::TARGET_ID, null, ModeratorRole::Member, true, true, true, 1,
            ModeratorAssignmentDecision::InvalidRequestedRole,
        ];
        yield 'last administrator cannot be removed' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, self::TARGET_ID, ModeratorRole::Administrator, null, true, true, true, 1,
            ModeratorAssignmentDecision::LastAdministratorProtected,
        ];
        yield 'administrator may be demoted when another remains' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, self::TARGET_ID, ModeratorRole::Administrator, ModeratorRole::Moderator, true, true, true, 2,
            ModeratorAssignmentDecision::Allowed,
        ];
        yield 'unchanged role has no write' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, self::TARGET_ID, ModeratorRole::VideoModerator, ModeratorRole::VideoModerator, true, true, true, 2,
            ModeratorAssignmentDecision::Unchanged,
        ];
        yield 'inconsistent roster is rejected' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, self::TARGET_ID, ModeratorRole::Administrator, null, true, true, true, 0,
            ModeratorAssignmentDecision::InvalidRosterState,
        ];
        yield 'empty admin roster is rejected before a grant' => [
            ['ROLE_ADMIN'], self::ACTOR_ID, self::TARGET_ID, null, ModeratorRole::VideoModerator, true, true, true, 0,
            ModeratorAssignmentDecision::InvalidRosterState,
        ];
    }
}
