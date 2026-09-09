<?php

namespace App\Services\Reports;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read model behind the global Reports workspace.
 *
 * The workspace is a work queue over the four report-capable modules that actually own a
 * preview/output workflow today: the CI / BI Report, the Business Report, the Residence Check and
 * the Business Check. It creates nothing: there is no reports table, no second status engine and no
 * GeneratedReport requirement — every row is derived from the module's own authoritative record, so
 * completing a report through its existing workflow is what moves it from Pending to Completed.
 *
 * Authoritative completion per module:
 *   CI / BI Report   cibi_reports.state       — 'complete' is Completed, anything else Pending.
 *   Business Report  income_sources.state     — 'complete' AND an actually-submitted business_reports
 *                                               row (revision > 1, the same marker
 *                                               ClientFolderOverview uses) is Completed.
 *   Residence Check  the residence_checks row — the table has no state column; saving the check
 *                                               through its own workflow IS completing it, so an
 *                                               existing row is Completed.
 *   Business Check   the business_checks row  — same as above. A check that references a business
 *                                               is scoped to that exact income_source_id (one
 *                                               business's check never completes another's); a
 *                                               manual check references none and stands on its own
 *                                               business_checks row.
 *
 * Modules whose record does not exist yet are still listed, as derived Pending rows with a
 * null source_id. They are pure read-model rows: opening this page never writes one.
 *
 * Person scope is carried per row and never merged — co_maker_id NULL is the Applicant, a set value
 * is that one exact Co-Maker. Business rows additionally carry their exact income_source_id, so
 * Business A can never link into Business B.
 */
class ReportWorkspaceQuery
{
    private const PER_PAGE = 15;

    /** The only report-capable modules with a real preview/output workflow. */
    public const KINDS = [
        'cibi' => 'CI / BI Report',
        'business_report' => 'Business Report',
        'residence_check' => 'Residence Check',
        'business_check' => 'Business Check',
    ];

    /** The two user-facing statuses, and the tab slug that selects each. */
    public const TABS = ['all', 'pending', 'completed'];

    /**
     * Sortable columns, keyed by the slug the table header uses. This map is the only way a sort
     * reaches the query: the request is validated against these keys, so no client value is ever
     * interpolated into an ORDER BY. Sorting runs over the whole result set inside the database —
     * it is never a reorder of the rows that happen to be on the current page.
     */
    public const SORTS = [
        'client' => 'client_name',
        // Applicant rows carry a NULL co_maker_id, so ascending lists Applicant before Co-Maker —
        // the same order the two labels read in.
        'client_type' => 'co_maker_id',
        'report_type' => 'kind',
        // By actionability (Pending first ascending), which is what the column actually means here.
        'status' => 'status_rank',
        'updated' => 'sort_date',
    ];

    /**
     * @param  array{search?: ?string, report_type?: ?string, person?: ?string, tab?: ?string, from?: ?string, to?: ?string}  $filters
     * @return LengthAwarePaginator<int, ReportWorkItem>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->filtered($user, $filters);
        $column = self::SORTS[$filters['sort'] ?? ''] ?? null;

        if ($column === null) {
            // Default: actionable work first, then the freshest within each group — Pending by most
            // recently touched, Completed by most recently completed (both are `sort_date`, which is
            // the completion timestamp once a row is complete and the record's updated_at before).
            $query->orderBy('status_rank')->orderByDesc('sort_date');
        } else {
            $query->orderBy($column, ($filters['direction'] ?? null) === 'desc' ? 'desc' : 'asc')
                ->orderByDesc('sort_date');
        }

        $paginator = $query
            // Deterministic tiebreak, so two rows with equal sort values never swap between pages.
            ->orderBy('kind')
            ->orderBy('source_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        // Resolved once for the whole page rather than per row, so the CI / BI icon costs one extra
        // query at most and the page still cannot regress into N+1.
        $prefilled = $this->residencePrefilledKeys($paginator->getCollection());

        return $paginator->through(fn (object $row): ReportWorkItem => ReportWorkItem::fromRow(
            $row,
            $row->kind === 'cibi' && in_array($this->personKey($row->client_folder_id, $row->co_maker_id), $prefilled, true),
        ));
    }

    /**
     * Which exact people on this page already have a Residence Check that the still-unsaved CI / BI
     * form would runtime-prefill from. Read-only and derived per render: nothing is stored, no CI/BI
     * row is created, and deleting the Residence Check simply drops the key on the next render.
     *
     * The rule is CibiReportFormData's own: the exact person's most recent Residence Check supplies
     * the CI / BI Start Date from `ci_date`, and the Present Address from `location`. Because
     * `residence_checks.ci_date` is NOT NULL, that latest row always contributes at least the CI
     * Date — so "this person has a Residence Check whose ci_date or location is set" and "the latest
     * one prefills something" are the same statement, and this stays one set-based query rather than
     * a per-person latest-row lookup. Scoping is the usual convention: NULL co_maker_id is the
     * Applicant, a set one is that exact Co-Maker, so one person's check can never light up another's.
     *
     * @param  Collection<int, object>  $rows
     * @return list<string>
     */
    private function residencePrefilledKeys(Collection $rows): array
    {
        $folderIds = $rows->where('kind', 'cibi')->pluck('client_folder_id')->unique()->values();
        if ($folderIds->isEmpty()) {
            return [];
        }

        return DB::table('residence_checks')
            ->whereIn('client_folder_id', $folderIds)
            ->where(fn (Builder $query) => $query
                ->whereNotNull('ci_date')
                ->orWhere(fn (Builder $location) => $location->whereNotNull('location')->where('location', '<>', '')))
            ->distinct()
            ->get(['client_folder_id', 'co_maker_id'])
            ->map(fn (object $check): string => $this->personKey($check->client_folder_id, $check->co_maker_id))
            ->all();
    }

    /** One folder + exact person, as a single comparable key ("12:" is folder 12's Applicant). */
    private function personKey(int|string $folderId, int|string|null $coMakerId): string
    {
        return $folderId.':'.($coMakerId ?? '');
    }

    /**
     * The four KPI counts, over the same unfiltered scope the tabs count, as a single aggregate —
     * the page must never load work items just to count them.
     *
     * @return array{total: int, pending: int, completed: int, completed_this_month: int}
     */
    public function summary(User $user): array
    {
        $timezone = (string) config('cims.display_timezone');
        $month = CarbonImmutable::now($timezone);

        $counts = DB::query()->fromSub($this->workItems($user), 'items')->selectRaw(
            'COUNT(*) as total,'
            .' SUM(CASE WHEN status_rank = 0 THEN 1 ELSE 0 END) as pending,'
            .' SUM(CASE WHEN status_rank = 1 THEN 1 ELSE 0 END) as completed,'
            .' SUM(CASE WHEN status_rank = 1 AND sort_date >= ? AND sort_date <= ? THEN 1 ELSE 0 END) as completed_this_month',
            [$this->utc($month->startOfMonth()), $this->utc($month->endOfMonth())],
        )->first();

        return [
            'total' => (int) ($counts->total ?? 0),
            'pending' => (int) ($counts->pending ?? 0),
            'completed' => (int) ($counts->completed ?? 0),
            'completed_this_month' => (int) ($counts->completed_this_month ?? 0),
        ];
    }

    /**
     * Filtering happens on the assembled work-item set rather than inside each branch, so every
     * filter has exactly one definition and can never mean two different things per module.
     *
     * @param  array<string, mixed>  $filters
     */
    private function filtered(User $user, array $filters): Builder
    {
        $timezone = (string) config('cims.display_timezone');
        $tab = $filters['tab'] ?? null;

        return DB::query()->fromSub($this->workItems($user), 'items')
            // Client name only — deliberately one predictable field. The client/folder number, the
            // Co-Maker, the business and the report type are all filtered through their own
            // controls instead, so typing here can never quietly match something else.
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->where('client_name', 'like', '%'.$search.'%'))
            // Set once the user picks an exact client from the suggestions: two accessible folders
            // may share a display name, and this keeps the selection tied to the one they chose.
            ->when($filters['client_folder_id'] ?? null, fn (Builder $query, $folderId) => $query
                ->where('client_folder_id', (int) $folderId))
            ->when($filters['report_type'] ?? null, fn (Builder $query, string $kind) => $query->where('kind', $kind))
            ->when(($filters['person'] ?? null) === 'applicant', fn (Builder $query) => $query->whereNull('co_maker_id'))
            ->when(($filters['person'] ?? null) === 'co_maker', fn (Builder $query) => $query->whereNotNull('co_maker_id'))
            ->when($tab === 'pending', fn (Builder $query) => $query->where('status_rank', 0))
            ->when($tab === 'completed', fn (Builder $query) => $query->where('status_rank', 1))
            // Dates arrive as calendar days in the display timezone; sort_date is stored in UTC, so
            // the whole local day is converted rather than compared as a naive string.
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query
                ->where('sort_date', '>=', $this->utc(CarbonImmutable::parse($from, $timezone)->startOfDay())))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query
                ->where('sort_date', '<=', $this->utc(CarbonImmutable::parse($to, $timezone)->endOfDay())));
    }

    /**
     * Every report-capable work item as one UNION ALL, so filtering, ordering, counting and
     * pagination all stay in the database instead of being merged in PHP.
     *
     * Each branch produces the identical column list, and each already carries everything the table
     * renders — client, person and business name — so the page needs no per-row lookups and
     * cannot regress into N+1.
     *
     * Scope is the shared CI team workspace: every branch is joined to a live client_folders row,
     * which is exactly what ClientFolder::accessibleTo() means for active folders (a passthrough),
     * while recycled folders stay out through their soft-delete column.
     */
    private function workItems(User $user): Builder
    {
        $branches = [
            $this->cibiReports(),
            $this->missingCibiReportsForApplicants(),
            $this->missingCibiReportsForCoMakers(),
            $this->businessReports(),
            $this->missingBusinessReportsForApplicants(),
            $this->missingBusinessReportsForCoMakers(),
            $this->residenceChecks(),
            $this->missingResidenceChecksForApplicants(),
            $this->missingResidenceChecksForCoMakers(),
            $this->businessChecks(),
            $this->missingBusinessChecksForApplicants(),
            $this->missingBusinessChecksForCoMakers(),
        ];

        $union = array_shift($branches);

        foreach ($branches as $branch) {
            $union->unionAll($branch);
        }

        return $union;
    }

    /** An existing CI / BI report, Completed once its own state says complete. */
    private function cibiReports(): Builder
    {
        return DB::table('cibi_reports as r')
            ->join('client_folders as f', 'f.id', '=', 'r.client_folder_id')
            ->leftJoin('co_makers as cm', 'cm.id', '=', 'r.co_maker_id')
            ->whereNull('f.deleted_at')
            ->selectRaw(
                "'cibi' as kind, r.id as source_id, r.client_folder_id as client_folder_id, r.co_maker_id as co_maker_id,"
                .' NULL as income_source_id,'
                ." CASE WHEN r.state = 'complete' THEN 1 ELSE 0 END as status_rank,"
                ." COALESCE(CASE WHEN r.state = 'complete' THEN r.completed_at END, r.updated_at) as sort_date,"
                .' f.display_name as client_name, cm.full_name as person_name,'
                .' NULL as business_name',
            );
    }

    /** The Applicant's CI / BI report before it has been started: derived, never written. */
    private function missingCibiReportsForApplicants(): Builder
    {
        return DB::table('client_folders as f')
            ->whereNull('f.deleted_at')
            ->whereNotExists(fn (Builder $exists) => $exists->select(DB::raw(1))->from('cibi_reports as r')
                ->whereColumn('r.client_folder_id', 'f.id')->whereNull('r.co_maker_id'))
            ->selectRaw(
                "'cibi' as kind, NULL as source_id, f.id as client_folder_id, NULL as co_maker_id,"
                .' NULL as income_source_id, 0 as status_rank, f.updated_at as sort_date,'
                .' f.display_name as client_name, NULL as person_name,'
                .' NULL as business_name',
            );
    }

    /** The same for one exact Co-Maker — never merged with the Applicant's own row. */
    private function missingCibiReportsForCoMakers(): Builder
    {
        return DB::table('co_makers as cm')
            ->join('client_folders as f', 'f.id', '=', 'cm.client_folder_id')
            ->whereNull('f.deleted_at')
            ->whereNotExists(fn (Builder $exists) => $exists->select(DB::raw(1))->from('cibi_reports as r')
                ->whereColumn('r.client_folder_id', 'f.id')->whereColumn('r.co_maker_id', 'cm.id'))
            ->selectRaw(
                "'cibi' as kind, NULL as source_id, f.id as client_folder_id, cm.id as co_maker_id,"
                .' NULL as income_source_id, 0 as status_rank, cm.updated_at as sort_date,'
                .' f.display_name as client_name, cm.full_name as person_name,'
                .' NULL as business_name',
            );
    }

    /**
     * One Business Report per business IncomeSource, identified by that exact income_source_id.
     * Fallback ("Sources of Income Declared by Client") sources are a different official form and
     * are not one of the four eligible modules, so they are excluded here.
     */
    private function businessReports(): Builder
    {
        return $this->businessSources()
            ->leftJoin('co_makers as cm', 'cm.id', '=', 's.co_maker_id')
            // A Business Report that was intentionally deleted stays gone: without this the branch
            // would immediately re-synthesise the same business as a "Create Report" work item,
            // because a deleted report and a never-created one are indistinguishable by row
            // absence alone. Saving a report for this business clears the marker.
            ->whereNull('s.business_report_deleted_at')
            ->selectRaw(
                "'business_report' as kind, CASE WHEN EXISTS (SELECT 1 FROM business_reports started WHERE started.income_source_id = s.id) THEN s.id ELSE NULL END as source_id, s.client_folder_id as client_folder_id,"
                .' s.co_maker_id as co_maker_id, s.id as income_source_id,'
                ." CASE WHEN s.state = 'complete' AND s.revision > 1"
                .' AND EXISTS (SELECT 1 FROM business_reports br WHERE br.income_source_id = s.id)'
                .' THEN 1 ELSE 0 END as status_rank,'
                ." COALESCE(CASE WHEN s.state = 'complete' THEN s.completed_at END, s.updated_at) as sort_date,"
                .' f.display_name as client_name, cm.full_name as person_name,'
                ." COALESCE(NULLIF(s.business_name, ''), s.source_name) as business_name",
            );
    }

    /** One virtual, unbound Business Report while the Applicant has no legitimate business. */
    private function missingBusinessReportsForApplicants(): Builder
    {
        return $this->missingBusinessItemsForApplicants('business_report');
    }

    /** One virtual, unbound Business Report while this exact Co-Maker has no legitimate business. */
    private function missingBusinessReportsForCoMakers(): Builder
    {
        return $this->missingBusinessItemsForCoMakers('business_report');
    }

    /** A saved Residence Check is the report content itself — the table carries no separate state. */
    private function residenceChecks(): Builder
    {
        return DB::table('residence_checks as c')
            ->join('client_folders as f', 'f.id', '=', 'c.client_folder_id')
            ->leftJoin('co_makers as cm', 'cm.id', '=', 'c.co_maker_id')
            ->whereNull('f.deleted_at')
            ->selectRaw(
                "'residence_check' as kind, c.id as source_id, c.client_folder_id as client_folder_id,"
                .' c.co_maker_id as co_maker_id, NULL as income_source_id, 1 as status_rank,'
                .' c.updated_at as sort_date, f.display_name as client_name,'
                .' cm.full_name as person_name, NULL as business_name',
            );
    }

    private function missingResidenceChecksForApplicants(): Builder
    {
        return DB::table('client_folders as f')
            ->whereNull('f.deleted_at')
            ->whereNotExists(fn (Builder $exists) => $exists->select(DB::raw(1))->from('residence_checks as c')
                ->whereColumn('c.client_folder_id', 'f.id')->whereNull('c.co_maker_id'))
            ->selectRaw(
                "'residence_check' as kind, NULL as source_id, f.id as client_folder_id, NULL as co_maker_id,"
                .' NULL as income_source_id, 0 as status_rank, f.updated_at as sort_date,'
                .' f.display_name as client_name, NULL as person_name,'
                .' NULL as business_name',
            );
    }

    private function missingResidenceChecksForCoMakers(): Builder
    {
        return DB::table('co_makers as cm')
            ->join('client_folders as f', 'f.id', '=', 'cm.client_folder_id')
            ->whereNull('f.deleted_at')
            ->whereNotExists(fn (Builder $exists) => $exists->select(DB::raw(1))->from('residence_checks as c')
                ->whereColumn('c.client_folder_id', 'f.id')->whereColumn('c.co_maker_id', 'cm.id'))
            ->selectRaw(
                "'residence_check' as kind, NULL as source_id, f.id as client_folder_id, cm.id as co_maker_id,"
                .' NULL as income_source_id, 0 as status_rank, cm.updated_at as sort_date,'
                .' f.display_name as client_name, cm.full_name as person_name,'
                .' NULL as business_name',
            );
    }

    /**
     * A saved business_checks row IS the completed Business Check — the table has no state column,
     * so an existing row is Completed exactly like a Residence Check. This is the authoritative
     * branch for BOTH shapes the independent Business Check architecture allows:
     *
     *   linked  income_source_id set    — one exact business; one business's check never completes
     *                                     another's, and the row keeps that exact id.
     *   manual  income_source_id NULL   — no IncomeSource and no BusinessReport exists at all, so
     *                                     the work item is built from the Business Check itself.
     *
     * The join to income_sources is therefore a LEFT join: an inner one silently dropped every
     * manual check from the workspace. `s.deleted_at IS NULL` still keeps a linked check off the
     * list once its business is recycled, and passes for a manual one (no matched row at all).
     *
     * Display name prefers the business's own name for a linked check (unchanged), and falls back
     * to the Business Check's own saved business_name snapshot — which is all a manual check has.
     */
    private function businessChecks(): Builder
    {
        return DB::table('business_checks as c')
            ->join('client_folders as f', 'f.id', '=', 'c.client_folder_id')
            ->leftJoin('income_sources as s', 's.id', '=', 'c.income_source_id')
            ->leftJoin('co_makers as cm', 'cm.id', '=', 'c.co_maker_id')
            ->whereNull('f.deleted_at')
            ->whereNull('s.deleted_at')
            ->selectRaw(
                "'business_check' as kind, c.id as source_id, c.client_folder_id as client_folder_id,"
                .' c.co_maker_id as co_maker_id, c.income_source_id as income_source_id, 1 as status_rank,'
                .' c.updated_at as sort_date, f.display_name as client_name,'
                .' cm.full_name as person_name,'
                ." COALESCE(NULLIF(s.business_name, ''), NULLIF(s.source_name, ''), NULLIF(c.business_name, '')) as business_name",
            );
    }

    /**
     * The single generic "Business Check — Pending" entry point for one exact person.
     *
     * This is a CREATE ENTRY POINT, not a row standing for one particular business. Business Report
     * and Business Check are independent workflows, so having two, three or ten Business Reports
     * must never produce two, three or ten named Pending Business Check rows — the CI picks which
     * business the check is for inside the Business Check form itself. The row therefore carries no
     * income_source_id and no business_name at all, and at most one exists per person.
     *
     * It is shown while there is still somewhere legitimate for a new Business Check to go:
     *
     *   - at least one eligible business of this person's has no saved Business Check yet, OR
     *   - this person has no legitimate business at all AND has not yet recorded a manual,
     *     standalone Business Check (income_source_id NULL) — the zero-business entry point.
     *
     * Once every eligible business has its own check, and any zero-business case has been answered
     * by a manual check, no entry point is needed and the row disappears. A legitimate new business
     * created later brings it back on the next authoritative read. Saved business_checks rows are
     * never touched by this — they come from businessChecks() above and stay business-specific.
     */
    private function missingBusinessChecksForApplicants(): Builder
    {
        return DB::table('client_folders as f')
            ->whereNull('f.deleted_at')
            ->where(fn (Builder $query) => $query
                ->whereExists(fn (Builder $exists) => $this->eligibleUncheckedBusiness($exists)
                    ->whereColumn('s.client_folder_id', 'f.id')
                    ->whereNull('s.co_maker_id'))
                ->orWhere(fn (Builder $zeroBusiness) => $zeroBusiness
                    ->whereNotExists(fn (Builder $exists) => $this->scopeExistingBusiness($exists)
                        ->whereColumn('s.client_folder_id', 'f.id')
                        ->whereNull('s.co_maker_id'))
                    ->whereNotExists(fn (Builder $exists) => $exists->select(DB::raw(1))->from('business_checks as mc')
                        ->whereColumn('mc.client_folder_id', 'f.id')
                        ->whereNull('mc.co_maker_id')
                        ->whereNull('mc.income_source_id'))))
            ->selectRaw(
                "'business_check' as kind, NULL as source_id, f.id as client_folder_id, NULL as co_maker_id,"
                .' NULL as income_source_id, 0 as status_rank, f.updated_at as sort_date,'
                .' f.display_name as client_name, NULL as person_name, NULL as business_name',
            );
    }

    /** The same single entry point for one exact Co-Maker — never merged with anyone else's. */
    private function missingBusinessChecksForCoMakers(): Builder
    {
        return DB::table('co_makers as cm')
            ->join('client_folders as f', 'f.id', '=', 'cm.client_folder_id')
            ->whereNull('f.deleted_at')
            ->where(fn (Builder $query) => $query
                ->whereExists(fn (Builder $exists) => $this->eligibleUncheckedBusiness($exists)
                    ->whereColumn('s.client_folder_id', 'f.id')
                    ->whereColumn('s.co_maker_id', 'cm.id'))
                ->orWhere(fn (Builder $zeroBusiness) => $zeroBusiness
                    ->whereNotExists(fn (Builder $exists) => $this->scopeExistingBusiness($exists)
                        ->whereColumn('s.client_folder_id', 'f.id')
                        ->whereColumn('s.co_maker_id', 'cm.id'))
                    ->whereNotExists(fn (Builder $exists) => $exists->select(DB::raw(1))->from('business_checks as mc')
                        ->whereColumn('mc.client_folder_id', 'f.id')
                        ->whereColumn('mc.co_maker_id', 'cm.id')
                        ->whereNull('mc.income_source_id'))))
            ->selectRaw(
                "'business_check' as kind, NULL as source_id, f.id as client_folder_id, cm.id as co_maker_id,"
                .' NULL as income_source_id, 0 as status_rank, cm.updated_at as sort_date,'
                .' f.display_name as client_name, cm.full_name as person_name, NULL as business_name',
            );
    }

    /**
     * A business this person could legitimately record a Business Check against right now: a real
     * dedicated business, not recycled, not suppressed on either side, and without a saved Business
     * Check of its own yet.
     *
     * The two suppressions are independent and both mean "do not re-synthesise this work item".
     * business_report_deleted_at: deleting the Business Report also retires a Business Check that
     * was still only virtual. business_check_deleted_at: the Business Check itself was
     * intentionally deleted, which suppresses only this and never the Business Report.
     */
    private function eligibleUncheckedBusiness(Builder $query): Builder
    {
        return $this->scopeExistingBusiness($query)
            ->whereNull('s.business_report_deleted_at')
            ->whereNull('s.business_check_deleted_at')
            ->whereNotExists(fn (Builder $exists) => $exists->select(DB::raw(1))->from('business_checks as c')
                ->whereColumn('c.income_source_id', 's.id'));
    }

    /**
     * A zero-business placeholder is derived from the person, never inserted. The NOT EXISTS uses
     * the same dedicated-business definition as the bound branches, so the placeholder disappears
     * atomically on the first authoritative Reports read after a real business is established.
     */
    private function missingBusinessItemsForApplicants(string $kind): Builder
    {
        return DB::table('client_folders as f')
            ->whereNull('f.deleted_at')
            ->whereNotExists(fn (Builder $exists) => $this->scopeExistingBusiness($exists)
                ->whereColumn('s.client_folder_id', 'f.id')
                ->whereNull('s.co_maker_id'))
            ->selectRaw(
                "'{$kind}' as kind, NULL as source_id, f.id as client_folder_id, NULL as co_maker_id,"
                .' NULL as income_source_id, 0 as status_rank, f.updated_at as sort_date,'
                .' f.display_name as client_name, NULL as person_name, NULL as business_name',
            );
    }

    private function missingBusinessItemsForCoMakers(string $kind): Builder
    {
        return DB::table('co_makers as cm')
            ->join('client_folders as f', 'f.id', '=', 'cm.client_folder_id')
            ->whereNull('f.deleted_at')
            ->whereNotExists(fn (Builder $exists) => $this->scopeExistingBusiness($exists)
                ->whereColumn('s.client_folder_id', 'f.id')
                ->whereColumn('s.co_maker_id', 'cm.id'))
            ->selectRaw(
                "'{$kind}' as kind, NULL as source_id, f.id as client_folder_id, cm.id as co_maker_id,"
                .' NULL as income_source_id, 0 as status_rank, cm.updated_at as sort_date,'
                .' f.display_name as client_name, cm.full_name as person_name, NULL as business_name',
            );
    }

    /** Apply the legitimate dedicated-business identity rules inside a NOT EXISTS subquery. */
    private function scopeExistingBusiness(Builder $query): Builder
    {
        return $query
            ->select(DB::raw(1))
            ->from('income_sources as s')
            ->join('income_source_templates as t', 't.id', '=', 's.income_source_template_id')
            ->whereNull('s.deleted_at')
            ->where('t.is_fallback', false)
            ->where('t.form_handler', 'dedicated-business');
    }

    /**
     * Business (non-fallback) income sources on a live folder — the shared base of both the Business
     * Report and the Business Check branches. Soft-deleted sources are excluded exactly as the
     * IncomeSource model's own scope excludes them.
     */
    private function businessSources(): Builder
    {
        return DB::table('income_sources as s')
            ->join('client_folders as f', 'f.id', '=', 's.client_folder_id')
            ->join('income_source_templates as t', 't.id', '=', 's.income_source_template_id')
            ->whereNull('s.deleted_at')
            ->whereNull('f.deleted_at')
            ->where('t.is_fallback', false);
    }

    private function utc(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Y-m-d H:i:s');
    }
}
