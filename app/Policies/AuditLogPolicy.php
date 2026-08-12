<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use App\Policies\Concerns\ChecksRoles;

class AuditLogPolicy
{
    use ChecksRoles;

    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->isAdministrator($user);
    }
}
