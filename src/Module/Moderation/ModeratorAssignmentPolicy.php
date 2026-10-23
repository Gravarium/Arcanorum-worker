<?php

declare(strict_types=1);

namespace App\Module\Moderation;

final readonly class ModeratorAssignmentPolicy
{
    public function __construct(private ModeratorAccessPolicy $accessPolicy)
    {
    }

    /**
     * Call with freshly locked role state inside the host application's role-change transaction.
     * The CSRF and MFA flags must come from verified request/session state, never request fields.
     *
     * @param mixed $actorRoles roles from the freshly loaded actor identity
     */
    public function decide(
        mixed $actorRoles,
        string $actorId,
        string $targetId,
        ?ModeratorRole $currentRole,
        ?ModeratorRole $requestedRole,
        bool $actorMfaConfigured,
        bool $targetMfaConfigured,
        bool $csrfVerified,
        int $administratorCount,
    ): ModeratorAssignmentDecision {
        if (!$csrfVerified) {
            return ModeratorAssignmentDecision::CsrfRequired;
        }

        if (!$this->accessPolicy->isAdministrator($actorRoles)) {
            return ModeratorAssignmentDecision::ActorDenied;
        }

        if (!$actorMfaConfigured) {
            return ModeratorAssignmentDecision::ActorMfaRequired;
        }

        if (!self::isValidIdentity($actorId) || !self::isValidIdentity($targetId)) {
            return ModeratorAssignmentDecision::InvalidIdentity;
        }

        if (hash_equals($actorId, $targetId)) {
            return ModeratorAssignmentDecision::SelfChangeForbidden;
        }

        if ($administratorCount < 1) {
            return ModeratorAssignmentDecision::InvalidRosterState;
        }

        if (null !== $requestedRole && !$targetMfaConfigured) {
            return ModeratorAssignmentDecision::TargetMfaRequired;
        }

        if (ModeratorRole::Administrator === $currentRole
            && ModeratorRole::Administrator !== $requestedRole
            && $administratorCount <= 1
        ) {
            return ModeratorAssignmentDecision::LastAdministratorProtected;
        }

        if (null !== $requestedRole && !$requestedRole->isAssignable()) {
            return ModeratorAssignmentDecision::InvalidRequestedRole;
        }

        if ($currentRole === $requestedRole) {
            return ModeratorAssignmentDecision::Unchanged;
        }

        return ModeratorAssignmentDecision::Allowed;
    }

    private static function isValidIdentity(string $identity): bool
    {
        return 1 === preg_match('/\A[a-f0-9]{32}\z/', $identity);
    }
}
