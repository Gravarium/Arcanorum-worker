<?php

declare(strict_types=1);

namespace App\Module\Admin\Service;

use App\Entity\AdminRole;
use App\Entity\AdminUser;
use App\Module\Moderation\ModeratorAssignmentDecision;
use App\Module\Moderation\ModeratorAssignmentPolicy;
use App\Module\Moderation\ModeratorRole;
use App\Repository\AdminUserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;

final readonly class AdminModeratorAccessManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminUserRepository $adminUsers,
        private ModeratorAssignmentPolicy $assignmentPolicy,
    ) {
    }

    public function assign(AdminUser $actor, string $targetId, ?AdminRole $role, bool $csrfVerified): AdminRoleChangeResult
    {
        if (1 !== preg_match('/\A[a-f0-9]{32}\z/', $targetId)) {
            return AdminRoleChangeResult::TargetNotFound;
        }
        if ($actor->getId() === $targetId) {
            return AdminRoleChangeResult::SelfChangeForbidden;
        }

        $requestedRole = null === $role ? null : ModeratorRole::tryFrom($role->value);
        if (null !== $role && null === $requestedRole) {
            return AdminRoleChangeResult::InvalidRole;
        }

        return $this->entityManager->getConnection()->transactional(function (Connection $connection) use ($actor, $targetId, $role, $requestedRole, $csrfVerified): AdminRoleChangeResult {
            // Serialisiert Rollenänderungen, damit der ausführende Admin nicht parallel entmachtet wird.
            $connection->executeStatement('LOCK TABLE admin_users IN SHARE ROW EXCLUSIVE MODE');

            try {
                $this->entityManager->refresh($actor, LockMode::PESSIMISTIC_WRITE);
            } catch (EntityNotFoundException) {
                return AdminRoleChangeResult::ActorNoLongerAdministrator;
            }

            $target = $this->adminUsers->find($targetId);
            if (!$target instanceof AdminUser) {
                return AdminRoleChangeResult::TargetNotFound;
            }

            $this->entityManager->refresh($target, LockMode::PESSIMISTIC_WRITE);

            $decision = $this->assignmentPolicy->decide(
                $actor->getRoles(),
                $actor->getId(),
                $target->getId(),
                self::singleStoredRole($target->getRoles()),
                $requestedRole,
                null !== $actor->getMfaSecretCiphertext(),
                null !== $target->getMfaSecretCiphertext(),
                $csrfVerified,
                $this->adminUsers->countAdministratorsForRoleManagement(),
            );

            $result = match ($decision) {
                ModeratorAssignmentDecision::Allowed => null,
                ModeratorAssignmentDecision::Unchanged => AdminRoleChangeResult::Unchanged,
                ModeratorAssignmentDecision::ActorDenied => AdminRoleChangeResult::ActorNoLongerAdministrator,
                ModeratorAssignmentDecision::CsrfRequired => AdminRoleChangeResult::CsrfRequired,
                ModeratorAssignmentDecision::ActorMfaRequired => AdminRoleChangeResult::ActorMfaRequired,
                ModeratorAssignmentDecision::TargetMfaRequired => AdminRoleChangeResult::MfaRequired,
                ModeratorAssignmentDecision::InvalidRequestedRole => AdminRoleChangeResult::InvalidRole,
                ModeratorAssignmentDecision::InvalidIdentity => AdminRoleChangeResult::TargetNotFound,
                ModeratorAssignmentDecision::SelfChangeForbidden => AdminRoleChangeResult::SelfChangeForbidden,
                ModeratorAssignmentDecision::LastAdministratorProtected => AdminRoleChangeResult::LastAdministratorProtected,
                ModeratorAssignmentDecision::InvalidRosterState => AdminRoleChangeResult::InvalidRosterState,
            };
            if (null !== $result) {
                return $result;
            }

            $newRoles = null === $role ? [] : [$role->value];
            $target->setRoles($newRoles);
            $this->entityManager->flush();

            return AdminRoleChangeResult::Updated;
        });
    }

    /** @param list<string> $roles */
    private static function singleStoredRole(array $roles): ?ModeratorRole
    {
        $storedRoles = array_values(array_filter($roles, static fn (string $role): bool => 'ROLE_USER' !== $role));
        if (in_array(AdminRole::Moderator->value, $storedRoles, true)
            || (in_array(AdminRole::VideoModerator->value, $storedRoles, true)
                && in_array(AdminRole::CommentModerator->value, $storedRoles, true))
        ) {
            return ModeratorRole::Moderator;
        }

        if (1 !== count($storedRoles)) {
            return null;
        }

        return ModeratorRole::tryFrom($storedRoles[0]);
    }
}
