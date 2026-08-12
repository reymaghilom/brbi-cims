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

    protected function isKnownRole(User $user): bool
    {
        return in_array($user->role, UserRole::cases(), true);
    }
}
