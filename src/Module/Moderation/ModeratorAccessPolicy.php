<?php

declare(strict_types=1);

namespace App\Module\Moderation;

final class ModeratorAccessPolicy
{
    /**
     * @param mixed $roles roles from the authenticated identity; malformed or unknown values deny access
     */
    public function allows(mixed $roles, ModeratorPermission $permission): bool
    {
        $knownRoles = self::knownRoles($roles);
        if (null === $knownRoles) {
            return false;
        }

        return array_any($knownRoles, static fn (ModeratorRole $role): bool => $role->allows($permission));
    }

    /**
     * @param mixed $roles roles from the authenticated identity; malformed or unknown values deny access
     */
    public function isAdministrator(mixed $roles): bool
    {
        $knownRoles = self::knownRoles($roles);
        if (null === $knownRoles) {
            return false;
        }

        return array_any($knownRoles, static fn (ModeratorRole $role): bool => ModeratorRole::Administrator === $role);
    }

    /**
     * @return list<ModeratorRole>|null
     */
    private static function knownRoles(mixed $roles): ?array
    {
        if (!is_array($roles) || !array_is_list($roles)) {
            return null;
        }

        $knownRoles = [];
        foreach ($roles as $role) {
            if (!is_string($role)) {
                return null;
            }

            $knownRole = ModeratorRole::tryFrom($role);
            if (null === $knownRole) {
                return null;
            }

            $knownRoles[] = $knownRole;
        }

        $seen = [];
        foreach ($knownRoles as $knownRole) {
            if (isset($seen[$knownRole->value])) {
                return null;
            }

            $seen[$knownRole->value] = true;
        }

        if (in_array(ModeratorRole::Administrator, $knownRoles, true)
            && 1 < count(array_filter($knownRoles, static fn (ModeratorRole $role): bool => ModeratorRole::Member !== $role))
        ) {
            return null;
        }

        return $knownRoles;
    }
}
