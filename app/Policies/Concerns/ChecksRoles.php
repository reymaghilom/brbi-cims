<?php

namespace App\Policies\Concerns;

use App\Enums\UserRole;
use App\Models\User;

trait ChecksRoles
{
    protected function isAdministrator(User $user): bool
    {
        return $user->role === UserRole::Administrator;
    }

    /**
     * Administrators and Senior Credit Investigators may reassign a CIBI signatory.
     * Folder access is checked separately by the calling policy.
     */
    protected function canManageCibiSignatory(User $user): bool
    {
        return $user->role->canManageCibiSignatory();
    }

    protected function isKnownRole(User $user): bool
    {
        return in_array($user->role, UserRole::cases(), true);
    }
}
