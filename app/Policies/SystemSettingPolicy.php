<?php

namespace App\Policies;

use App\Models\SystemSetting;
use App\Models\User;
use App\Policies\Concerns\ChecksRoles;

class SystemSettingPolicy
{
    use ChecksRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, SystemSetting $setting): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, SystemSetting $setting): bool
    {
        return $this->isAdministrator($user);
    }

    public function delete(User $user, SystemSetting $setting): bool
    {
        return $this->isAdministrator($user);
    }
}
