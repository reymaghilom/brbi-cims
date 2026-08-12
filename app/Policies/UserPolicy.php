<?php

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\ChecksRoles;

class UserPolicy
{
    use ChecksRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, User $subject): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, User $subject): bool
    {
        return $this->isAdministrator($user);
    }

    public function changeStatus(User $user, User $subject): bool
    {
        return $this->isAdministrator($user) && ! $user->is($subject);
    }

    public function resetPassword(User $user, User $subject): bool
    {
        return $this->isAdministrator($user) && ! $user->is($subject);
    }

    public function delete(User $user, User $subject): bool
    {
        return false;
    }
}
