<?php

namespace App\Policies;

use App\Models\ClientFolder;
use App\Models\User;
use App\Policies\Concerns\ChecksRoles;
use Illuminate\Database\Eloquent\Model;

abstract class ClientFolderResourcePolicy
{
    use ChecksRoles;

    public function viewAny(User $user): bool
    {
        return $this->isKnownRole($user);
    }

    public function view(User $user, Model $resource): bool
    {
        return $this->canAccessFolder($user, $this->folderFor($resource));
    }

    public function create(User $user, ?ClientFolder $clientFolder = null): bool
    {
        return $clientFolder
            ? $this->canAccessFolder($user, $clientFolder)
            : $this->isKnownRole($user);
    }

    public function update(User $user, Model $resource): bool
    {
        return $this->view($user, $resource);
    }

    public function delete(User $user, Model $resource): bool
    {
        return $this->view($user, $resource);
    }

    public function restore(User $user, Model $resource): bool
    {
        return $this->view($user, $resource);
    }

    public function forceDelete(User $user, Model $resource): bool
    {
        return $this->isAdministrator($user);
    }

    public function generate(User $user, Model $resource): bool
    {
        return $this->update($user, $resource);
    }

    public function export(User $user, Model $resource): bool
    {
        return $this->view($user, $resource);
    }

    protected function folderFor(Model $resource): ?ClientFolder
    {
        return ClientFolder::withTrashed()->find($resource->getAttribute('client_folder_id'));
    }

    protected function canAccessFolder(User $user, ?ClientFolder $clientFolder): bool
    {
        return $clientFolder !== null
            && ($this->isAdministrator($user) || $clientFolder->assigned_ci_id === $user->id);
    }
}
