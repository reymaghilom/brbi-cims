<?php

namespace App\Services\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ClientFolderBrowser
{
    public function browse(User $user, array $filters): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? null;
        $sort = $filters['sort'] ?? 'updated';

        $query = ClientFolder::query()
            ->accessibleTo($user)
            ->with(['assignedInvestigator:id,full_name'])
            ->select([
                'id',
                'folder_number',
                'display_name',
                'last_name',
                'first_name',
                'assigned_ci_id',
                'status',
                'progress_percent',
                'created_at',
                'updated_at',
            ])
            ->when($search, fn ($query, $search) => $query->where('display_name', 'like', "%{$search}%"))
            ->when($status, fn ($query, $status) => $query->where('status', $status));

        match ($sort) {
            'created' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'client_name' => $query->orderBy('last_name')->orderBy('first_name')->orderBy('id'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };

        return $query->paginate(12)->withQueryString();
    }
}
