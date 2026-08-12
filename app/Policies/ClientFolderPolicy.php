<?php

namespace App\Policies;

use App\Models\ClientFolder;
use App\Models\User;
use App\Policies\Concerns\ChecksRoles;

class ClientFolderPolicy
{
    use ChecksRoles;

    public function viewAny(User $user): bool
    {
        return $this->isKnownRole($user);
    }

    public function view(User $user, ClientFolder $clientFolder): bool
    {
        return $this->isAdministrator($user) || $clientFolder->assigned_ci_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $this->isKnownRole($user);
    }

    public function update(User $user, ClientFolder $clientFolder): bool
    {
        return $this->view($user, $clientFolder);
    }

    public function delete(User $user, ClientFolder $clientFolder): bool
    {
        return $this->view($user, $clientFolder);
    }

    public function restore(User $user, ClientFolder $clientFolder): bool
    {
        return $this->isAdministrator($user);
    }

    public function forceDelete(User $user, ClientFolder $clientFolder): bool
    {
        return $this->isAdministrator($user);
    }
}
