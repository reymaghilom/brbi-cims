<?php

namespace App\Services\ClientFolders;

use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ClientFolderBrowser
{
    private const PER_PAGE = 12;

    public function browse(User $user, array $filters): LengthAwarePaginator
    {
        $search = $filters['search'] ?? null;
        $status = $filters['status'] ?? null;
        $sort = $filters['sort'] ?? 'updated';

        $query = ClientFolder::query()
            ->accessibleTo($user)
            ->with(['assignedInvestigator:id,full_name', 'creator:id,full_name', 'updater:id,full_name'])
            ->select([
                'id',
                'folder_number',
                'display_name',
                'last_name',
                'first_name',
                'assigned_ci_id',
                'created_by',
                'updated_by',
                'status',
                'progress_percent',
                'created_at',
                'updated_at',
            ])
            // Informational only: these authoritative folder-owned relationships decide which
            // permanent-delete warning the browser renders. Basic identity/profile fields are
            // deliberately excluded, and the DELETE endpoint still re-authorizes and runs
            // PurgeClientFolder's external-file safety checks independently.
            ->withExists([
                'cibiReports as has_cibi_data',
                'coMakers as has_co_maker_data',
                'incomeSources as has_income_source_data',
                'residenceBusinessReport as has_residence_business_data',
                'residenceChecks as has_residence_check_data',
                'businessChecks as has_business_check_data',
                'activities as has_activity_data',
                'mediaReferences as has_media_data',
                'generatedReports as has_generated_report_data',
                'completionResults as has_completion_data',
            ])
            ->when($search, fn ($query, $search) => $query->where('display_name', 'like', "%{$search}%"))
            ->when($status, fn ($query, $status) => $query->where('status', $status));

        match ($sort) {
            'created' => $query->orderByDesc('created_at')->orderByDesc('id'),
            'client_name' => $query->orderBy('last_name')->orderBy('first_name')->orderBy('id'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };

        $paginator = $query->paginate(self::PER_PAGE)->withQueryString();

        // A page beyond the last valid one (a hand-edited ?page=, a stale link, or a page that
        // stopped existing because folders were removed) would otherwise render as an empty grid
        // and trip the "No client folders yet" empty state, which is a lie whenever folders do
        // exist on earlier pages. Clamp to the last real page instead, so the empty state is only
        // ever reached when the filtered set is genuinely empty. Costs one extra count query only
        // in that out-of-range case; the normal path is untouched.
        if ($paginator->total() > 0 && $paginator->currentPage() > $paginator->lastPage()) {
            $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $paginator->lastPage())->withQueryString();
        }

        return $paginator;
    }
}
