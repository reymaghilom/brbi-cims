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

    /**
     * Clearing the operational workspace. Stated as its own ability rather than borrowed from
     * viewAny/update so the destructive action reads explicitly at every call site; it grants
     * nothing wider - Administrator only, exactly like every other ability here.
     */
    public function resetOperationalData(User $user): bool
    {
        return $this->isAdministrator($user);
    }
}
