<?php

namespace App\Services\ClientFolders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Collection;

class ClientFolderCreationOptions
{
    /**
     * @return Collection<int, User>
     */
    public function creditInvestigatorsFor(User $user): Collection
    {
        if ($user->role !== UserRole::Administrator) {
            return collect();
        }

        return User::query()
            ->where('role', UserRole::CreditInvestigator)
            ->where('status', UserStatus::Active)
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'employee_id']);
    }
}
