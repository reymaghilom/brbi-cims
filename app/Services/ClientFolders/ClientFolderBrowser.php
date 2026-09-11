<?php

namespace App\Services\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
                'activities as has_activity_data' => fn (Builder $query) => $this->withSavedWork($query),
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

    /**
     * Delete-warning detection only. Every new folder (and Co-Maker) is seeded with Pending
     * Barangay / Neighbor Checks and no audit entry, so those rows alone are not saved work. Any
     * other activity type counts, and a default counts as soon as anything departs from its seeded
     * state or a user saved it (UpdateCiActivity audits every save with metadata.activity_id).
     * This never affects what PurgeClientFolder deletes.
     */
    private function withSavedWork(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereDoesntHave('definition', fn (Builder $definition) => $definition->whereIn('code', ActivityDefinition::MANDATORY_DEFAULT_CODES))
                ->orWhere('status', '!=', ActivityStatus::Pending->value)
                ->orWhereNotNull('scheduled_at')
                ->orWhereNotNull('visit_date')
                ->orWhereNotNull('time_in')
                ->orWhereNotNull('time_out')
                ->orWhereNotNull('completed_at')
                ->orWhereNotNull('submitted_at');

            foreach (['remarks', 'visited_by', 'person_met_contact', 'supporting_reference', 'submitted_to', 'submission_note'] as $column) {
                $query->orWhere(fn (Builder $filled) => $filled->whereNotNull($column)->where($column, '!=', ''));
            }

            $query->orWhereHas('mediaReferences')
                ->orWhereHas('notes')
                ->orWhereExists(fn ($audit) => $audit->from('audit_logs')
                    ->whereColumn('audit_logs.client_folder_id', 'ci_activities.client_folder_id')
                    ->where('audit_logs.module', 'ci_activities')
                    ->whereColumn('audit_logs.metadata->activity_id', 'ci_activities.id'));
        });
    }
}
