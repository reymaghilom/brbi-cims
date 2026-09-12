<?php

namespace App\Services\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\ClientFolderStatus;
use App\Enums\RecordState;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CiActivityHistoryFeed;
use App\Services\ClientFolders\ClientFolderOverview;
use App\Services\Progress\MandatoryInvestigationRequirements;
use App\Services\Reports\ReportWorkItem;
use App\Services\Reports\ReportWorkspaceQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every number on the Dashboard comes from here, derived from the application's own workflow state —
 * there are no illustrative values anywhere on the page. The page is deliberately HYBRID:
 *
 * - Everything folder-level (the KPIs, both charts and the progress bars) describes the SHARED CI
 *   TEAM WORKSPACE, using exactly the scope the Client Folders page uses: ClientFolder::accessibleTo(),
 *   a passthrough for active folders because any Credit Investigator may work on any active folder
 *   (see ClientFolder::isAccessibleBy()). Trashed folders are excluded by the model's own soft-delete
 *   scope, exactly as that page does. This is why the Dashboard's folder total
 *   and the Client Folders page total agree.
 * - "My Work Today" is the one PERSONAL section: it answers "what do I need to work on", so it uses
 *   the responsibility rule the app already owns - `creator_id`, the same field the scheduled-today
 *   reminder feed scopes by (CiActivity::scopeScheduledTodayForCreator). `ci_activities.assigned_ci_id`
 *   is deliberately not used: nothing in the app writes it.
 * - Person-scoped records (CI activities, checks, reports) are counted through their own
 *   `client_folder_id`, so an Applicant row and a Co-Maker row are never merged into one another.
 *
 * All queries are aggregates or bounded lookups; nothing loads a folder graph per row.
 */
class DashboardData
{
    /** Trend ranges offered by the CI Completion Trend card, keyed by the value the view submits. */
    public const TREND_RANGES = ['7d' => '7 Days', '30d' => '30 Days', '12m' => '12 Months'];

    public const DEFAULT_TREND_RANGE = '7d';

    private const WORK_PAGE_SIZE = 5;

    private const RECENT_ACTIVITY_LIMIT = 3;

    public function __construct(private readonly ReportWorkspaceQuery $reports) {}

    public function for(User $user, ?string $trendRange = null, mixed $workPage = null): array
    {
        $trendRange = array_key_exists((string) $trendRange, self::TREND_RANGES) ? (string) $trendRange : self::DEFAULT_TREND_RANGE;
        $timezone = (string) config('cims.display_timezone');
        $now = CarbonImmutable::now($timezone);

        // One query also carries what the KPI detail lists show (name, updated, assigned CI), most
        // recently updated first - no per-folder lookups.
        $folders = $this->scopedFolders($user)
            ->leftJoin('users as assigned_ci', 'assigned_ci.id', '=', 'client_folders.assigned_ci_id')
            ->orderByDesc('client_folders.updated_at')
            ->orderByDesc('client_folders.id')
            ->get(['client_folders.id', 'client_folders.display_name', 'client_folders.status', 'client_folders.completed_at', 'client_folders.updated_at', 'assigned_ci.full_name as assigned_ci_name']);
        $folderIds = $folders->pluck('id');

        $mandatory = $this->mandatoryProgress($folderIds);
        $readyReports = $this->reports->completedItems($user);
        $needsAttention = $this->needsAttention($folderIds, $now, $timezone);
        $workload = $this->workload($folders, $mandatory);
        $recentActivity = $this->recentActivity($folderIds, $timezone);
        $trends = collect(self::TREND_RANGES)
            ->map(fn (string $label, string $key): array => $this->trend($user, $key, $now, $timezone))
            ->all();

        return [
            'greeting' => $this->greeting($now),
            'today' => $now,
            'summary' => $this->summary($folders, $mandatory, $readyReports, $needsAttention, $now, $timezone),
            'workload' => $workload,
            'needsAttention' => $needsAttention['folders'],
            'kpiDetails' => $this->kpiDetails($folders, $mandatory, $readyReports, $now, $timezone),
            // Every range is computed up front so the CI Completion Trend card can switch between
            // 7 Days / 30 Days / 12 Months instantly on the client, with no second request and no
            // loading state. Each entry is produced by the exact same trend() call the single-range
            // version used, so the calculation and labels per range are unchanged.
            'trends' => $trends,
            'trend' => $trends[$trendRange],
            'trendRange' => $trendRange,
            'trendRanges' => self::TREND_RANGES,
            'activityProgress' => $this->activityProgress($folderIds),
            'workToday' => $this->workToday($user, $folderIds, $now, max(1, (int) $workPage), $trendRange),
            'recentActivity' => $recentActivity['events'],
            'recentActivityHasMore' => $recentActivity['hasMore'],
            'recentActivityAll' => $recentActivity['allEvents'],
        ];
    }

    /**
     * The shared-workspace folder scope every folder-level metric is built on - identical to the one
     * ClientFolderBrowser uses for the Client Folders page, so the two totals can never disagree.
     */
    private function scopedFolders(User $user): Builder
    {
        return ClientFolder::query()->accessibleTo($user);
    }

    private function greeting(CarbonImmutable $now): string
    {
        return match (true) {
            $now->hour < 12 => 'Good Morning',
            $now->hour < 18 => 'Good Afternoon',
            default => 'Good Evening',
        };
    }

    /**
     * The three PROGRESS STATUSES of a Client Folder, as one mutually exclusive distribution over
     * the same active-folder set the Active Client Folders KPI counts - so the slices always add up
     * to the folder total and every percentage is that slice over that same total.
     *
     * All three read one authoritative source, mandatoryProgress(), and nothing else:
     *
     * - Not Started: 0% - no mandatory requirement met yet. A brand-new folder lives here,
     *   auto-generated pristine Barangay / Neighbor rows included, since those satisfy nothing.
     * - In Progress: isInProgress(), the IDENTICAL predicate the In Progress KPI is counted with,
     *   applied to the identical array. The slice and the card are therefore the same folder set,
     *   not two formulas that happen to agree.
     * - Completed: 100% - every mandatory requirement met. Deliberately the progress result rather
     *   than the stored ClientFolderStatus: the two are kept in step by
     *   ClientProgressService::recalculate(), and where they ever disagree the calculation is the
     *   authority this chart reports.
     *
     * Needs Attention is deliberately NOT a slice here. It is an overdue FLAG that cuts across all
     * three statuses - a folder can be half-finished and overdue, or finished and overdue on
     * optional Asset work - so making it a mutually exclusive bucket was what pulled overdue
     * folders out of the In Progress slice and broke agreement with the In Progress KPI. It keeps
     * its own KPI card, its own count and its own detail modal; see needsAttention().
     *
     * @param  Collection<int, ClientFolder>  $folders
     * @param  array<int, array{completed: int, total: int, percent: int, missing: list<string>}>  $mandatory
     */
    private function workload(Collection $folders, array $mandatory): array
    {
        $buckets = ['not_started' => 0, 'in_progress' => 0, 'completed' => 0];

        foreach ($folders as $folder) {
            $progress = $mandatory[$folder->id] ?? null;

            $bucket = match (true) {
                $this->isInProgress($progress) => 'in_progress',
                $progress !== null && $progress['missing'] === [] => 'completed',
                default => 'not_started',
            };
            $buckets[$bucket]++;
        }

        $total = $folders->count();

        return [
            'total' => $total,
            'segments' => collect([
                ['key' => 'not_started', 'label' => 'Not Started', 'tone' => 'amber'],
                ['key' => 'in_progress', 'label' => 'In Progress', 'tone' => 'brand'],
                ['key' => 'completed', 'label' => 'Completed', 'tone' => 'success'],
            ])->map(fn (array $segment): array => $segment + [
                'count' => $buckets[$segment['key']],
                'percent' => $this->percent($buckets[$segment['key']], $total),
            ])->all(),
        ];
    }

    /**
     * "Overdue" has to respect how a schedule was actually recorded, because the two kinds of
     * schedule mean different things:
     *
     * - With an explicit time (`scheduled_has_time`), `scheduled_at` is a real deadline instant and
     *   the activity is overdue once that instant passes.
     * - Date-only, `CiActivity::normalizeScheduleInput()` stores the date at 08:00 local — the
     *   default reminder time, not a deadline. Such an activity is only overdue once its whole
     *   scheduled DAY has passed, so an activity due today never turns red during its own due date.
     *
     * The date-only cutoff is derived through that same normalizer, so the comparison can never
     * drift from the convention the writer used. It applies to any record carrying status /
     * scheduled_at / scheduled_has_time: CI activities and their Bank / Coop and Asset targets alike.
     */
    private function whereOverdue(Builder $query, CarbonImmutable $now): Builder
    {
        [$startOfToday] = CiActivity::normalizeScheduleInput($now->format('Y-m-d'));

        return $query
            ->where('status', '!=', ActivityStatus::Completed)
            ->whereNotNull('scheduled_at')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $timed) => $timed->where('scheduled_has_time', true)->where('scheduled_at', '<', $now->utc()))
                ->orWhere(fn (Builder $dateOnly) => $dateOnly->where('scheduled_has_time', false)->where('scheduled_at', '<', $startOfToday)));
    }

    /**
     * The Needs Attention KPI and its detail list: every overdue item, grouped by Client Folder.
     *
     * Items are ordinary CI activities (Barangay, Neighbor, custom) plus the individual Bank / Coop
     * and Asset targets - those two parents are represented only by their targets, which carry the
     * authoritative per-institution / per-office schedule and status, so nothing is counted twice.
     * The KPI is the number of folders here (each once); the Workload chart keeps its own buckets.
     * Three queries with eager-loaded folder/person/definition; nothing runs per folder or item.
     *
     * @param  Collection<int, int>  $folderIds
     * @return array{folders: array<int, array{client: string, url: string, items: array<int, array<string, mixed>>}>, folder_count: int, item_count: int}
     */
    private function needsAttention(Collection $folderIds, CarbonImmutable $now, string $timezone): array
    {
        $targetParents = [ActivityDefinition::BANK_COOP_CHECK_CODE, ActivityDefinition::ASSET_CHECK_CODE];
        $parentRelations = ['activity:id,client_folder_id,co_maker_id,name', 'activity.clientFolder:id,display_name', 'activity.coMaker:id,full_name'];

        $activities = $this->whereOverdue(CiActivity::query()->whereIn('client_folder_id', $folderIds), $now)
            ->whereDoesntHave('definition', fn (Builder $definition) => $definition->whereIn('code', $targetParents))
            ->with(['clientFolder:id,display_name', 'coMaker:id,full_name', 'definition'])
            ->get()
            ->map(fn (CiActivity $activity): array => $this->needsAttentionItem($activity->clientFolder, $activity->coMaker, $activity->display_name, $activity, $now, $timezone));

        $bankTargets = $this->whereOverdue(CiActivityBankTarget::query()->whereHas('activity', fn (Builder $activity) => $activity->whereIn('client_folder_id', $folderIds)), $now)
            ->with($parentRelations)
            ->get()
            ->map(fn (CiActivityBankTarget $target): array => $this->needsAttentionItem(
                $target->activity->clientFolder,
                $target->activity->coMaker,
                $target->activity->name.' — '.$target->institution_name.($target->branch_location ? ' ('.$target->branch_location.')' : ''),
                $target, $now, $timezone,
            ));

        $assetTargets = $this->whereOverdue(CiActivityAssetTarget::query()->whereHas('activity', fn (Builder $activity) => $activity->whereIn('client_folder_id', $folderIds)), $now)
            ->with($parentRelations)
            ->get()
            ->map(fn (CiActivityAssetTarget $target): array => $this->needsAttentionItem(
                $target->activity->clientFolder,
                $target->activity->coMaker,
                $target->activity->name.' — '.$target->assessorLabel().($target->office_location ? ' ('.$target->office_location.')' : ''),
                $target, $now, $timezone,
            ));

        $items = $activities->concat($bankTargets)->concat($assetTargets)->sortBy('sort')->values();
        $folders = $items->groupBy('folder_id')->map(fn (Collection $folderItems): array => [
            'client' => $folderItems->first()['client'],
            'url' => route('client-folders.activities.index', $folderItems->first()['folder_id']),
            'items' => $folderItems->values()->all(),
        ])->values();

        return [
            'folders' => $folders->all(),
            'folder_ids' => $items->pluck('folder_id')->unique()->map(fn ($id): int => (int) $id)->values()->all(),
            'folder_count' => $folders->count(),
            'item_count' => $items->count(),
        ];
    }

    private function needsAttentionItem(?ClientFolder $folder, ?CoMaker $coMaker, string $label, CiActivity|CiActivityBankTarget|CiActivityAssetTarget $record, CarbonImmutable $now, string $timezone): array
    {
        $due = CarbonImmutable::instance($record->scheduled_at)->timezone($timezone);

        return [
            'folder_id' => $folder?->id,
            'client' => $folder?->display_name ?? 'Unnamed client',
            'person' => $coMaker ? 'Co-Maker: '.$coMaker->full_name : 'Applicant',
            'url' => $folder ? route('client-folders.activities.index', [$folder->id] + ActivePersonResolver::queryParamsForId($coMaker?->id)) : null,
            'label' => $label,
            'status' => $record->status->label(),
            'due' => $record->scheduled_has_time ? $due->format('M j, Y · g:i A') : $due->format('M j, Y'),
            'days_overdue' => (int) $due->startOfDay()->diffInDays($now->startOfDay()),
            'sort' => $record->scheduled_at->getTimestamp(),
        ];
    }

    /** The same rule as the query above, applied to one already-loaded activity. */
    private function isOverdue(CiActivity|CiActivityBankTarget|CiActivityAssetTarget $activity, CarbonImmutable $now): bool
    {
        if ($activity->scheduled_at === null || $activity->status === ActivityStatus::Completed) {
            return false;
        }

        [$startOfToday] = CiActivity::normalizeScheduleInput($now->format('Y-m-d'));

        return $activity->scheduled_has_time
            ? $activity->scheduled_at->lessThan($now)
            : $activity->scheduled_at->lessThan($startOfToday);
    }

    /**
     * @param  Collection<int, ClientFolder>  $folders
     * @param  array<int, array{completed: int, total: int, percent: int, missing: list<string>}>  $mandatory
     * @param  Collection<int, ReportWorkItem>  $readyReports
     */
    private function summary(Collection $folders, array $mandatory, Collection $readyReports, array $needsAttention, CarbonImmutable $now, string $timezone): array
    {
        return [
            'assigned' => $folders->count(),
            // Unique folders whose mandatory investigation work has started but is not finished.
            'in_progress' => collect($mandatory)->filter(fn (array $folder): bool => $this->isInProgress($folder))->count(),
            // Unique folders holding overdue work (each once), plus how many overdue items they hold.
            'needs_attention' => $needsAttention['folder_count'],
            'needs_attention_items' => $needsAttention['item_count'],
            'completed_this_month' => $this->completedThisMonth($folders, $now, $timezone)->count(),
            // Completed report RECORDS, exactly as Global Reports counts them (ReportWorkspaceQuery):
            // CI / BI, Business Report (per income source), Residence and Business Check, per
            // Applicant / Co-Maker. Generated files are not counted, so re-generating or
            // re-downloading an output never changes this number.
            'reports_ready' => $readyReports->count(),
        ];
    }

    /** @param  Collection<int, ClientFolder>  $folders */
    private function completedThisMonth(Collection $folders, CarbonImmutable $now, string $timezone): Collection
    {
        return $folders->filter(fn (ClientFolder $folder): bool => $folder->status === ClientFolderStatus::Completed
            && $folder->completed_at !== null
            && $folder->completed_at->timezone($timezone)->isSameMonth($now));
    }

    /**
     * Mandatory investigation progress for every folder, set-based: one query returns each folder's
     * seven Applicant flags and one returns each Co-Maker's four, all built from
     * MandatoryInvestigationRequirements' own predicates (the definition folder progress and the
     * In Progress KPI share). A folder is In Progress exactly when something here is missing.
     *
     * @param  Collection<int, int>  $folderIds
     * @return array<int, array{completed: int, total: int, percent: int, missing: list<string>}>
     */
    private function mandatoryProgress(Collection $folderIds): array
    {
        if ($folderIds->isEmpty()) {
            return [];
        }

        $flag = fn (string $requirement, ?string $coMakerColumn) => fn (QueryBuilder $record) => MandatoryInvestigationRequirements::recordQuery($record, $requirement, $coMakerColumn)->selectRaw('1')->limit(1);

        $applicants = DB::table('client_folders')->whereIn('client_folders.id', $folderIds)->select('client_folders.id');
        foreach (array_keys(MandatoryInvestigationRequirements::APPLICANT) as $requirement) {
            $applicants->selectSub($flag($requirement, null), 'met_'.$requirement);
        }
        $coMakers = DB::table('co_makers')
            ->join('client_folders', 'client_folders.id', '=', 'co_makers.client_folder_id')
            ->whereIn('client_folders.id', $folderIds)
            ->orderBy('co_makers.id')
            ->select('co_makers.id', 'co_makers.client_folder_id', 'co_makers.full_name');
        foreach (array_keys(MandatoryInvestigationRequirements::CO_MAKER) as $requirement) {
            $coMakers->selectSub($flag($requirement, 'co_makers.id'), 'met_'.$requirement);
        }
        $coMakersByFolder = $coMakers->get()->groupBy('client_folder_id');

        return $applicants->get()->mapWithKeys(function (object $folder) use ($coMakersByFolder): array {
            $missing = [];
            $total = 0;
            foreach (MandatoryInvestigationRequirements::APPLICANT as $requirement => $label) {
                $total++;
                if (! $folder->{'met_'.$requirement}) {
                    $missing[] = $label;
                }
            }
            foreach ($coMakersByFolder->get($folder->id, collect()) as $coMaker) {
                foreach (MandatoryInvestigationRequirements::CO_MAKER as $requirement => $label) {
                    $total++;
                    if (! $coMaker->{'met_'.$requirement}) {
                        $missing[] = 'Co-Maker: '.$coMaker->full_name.' — '.$label;
                    }
                }
            }
            $completed = $total - count($missing);

            return [(int) $folder->id => ['completed' => $completed, 'total' => $total, 'percent' => (int) round($completed / $total * 100), 'missing' => $missing]];
        })->all();
    }

    /**
     * Rows behind the Active Client Folders, In Progress, Completed This Month and Reports Ready
     * detail modals - built only from data already loaded above (no further queries). Each list is
     * the exact set its KPI counts.
     *
     * @param  Collection<int, ClientFolder>  $folders
     * @param  array<int, array{completed: int, total: int, percent: int, missing: list<string>}>  $mandatory
     * @param  Collection<int, ReportWorkItem>  $readyReports
     */
    private function kpiDetails(Collection $folders, array $mandatory, Collection $readyReports, CarbonImmutable $now, string $timezone): array
    {
        $folderRow = fn (ClientFolder $folder): array => [
            'client' => $folder->display_name ?: 'Unnamed client',
            'url' => route('client-folders.show', $folder->id),
            'status' => str($folder->status->value)->replace('_', ' ')->title()->toString(),
            'ci' => $folder->assigned_ci_name,
            'progress' => $mandatory[$folder->id] ?? null,
            'updated' => $folder->updated_at?->timezone($timezone)->format('M j, Y'),
        ];

        return [
            'active' => $folders->map($folderRow)->values()->all(),
            'in_progress' => $folders->filter(fn (ClientFolder $folder): bool => $this->isInProgress($mandatory[$folder->id] ?? null))
                ->map($folderRow)->values()->all(),
            'completed_this_month' => $this->completedThisMonth($folders, $now, $timezone)
                ->sortByDesc(fn (ClientFolder $folder) => $folder->completed_at->getTimestamp())
                ->map(fn (ClientFolder $folder): array => $folderRow($folder) + ['completed_on' => $folder->completed_at->timezone($timezone)->format('M j, Y')])
                ->values()->all(),
            // Folder -> person -> reports, in Global Reports' newest-completion-first order.
            'reports_ready' => $readyReports->groupBy('clientFolderId')->map(fn (Collection $items): array => [
                'client' => $items->first()->clientName,
                'people' => $items->groupBy(fn (ReportWorkItem $item) => $item->coMakerId ?? 0)->sortKeys()->map(fn (Collection $personItems): array => [
                    'person' => $personItems->first()->coMakerId === null ? 'Applicant' : 'Co-Maker: '.$personItems->first()->personName,
                    'reports' => $personItems->map(function (ReportWorkItem $item) use ($timezone): array {
                        // The report's OWN existing web output, reached exactly as Global Reports
                        // reaches it: CI / BI and Business Report are GET preview links carrying
                        // the exact person and the exact income source, and the two photo checks
                        // keep the shared POST batch-preview endpoint addressed by this one
                        // check's own id under that same person. Read-only navigation either way -
                        // it never generates a file, never downloads one and never writes a
                        // GeneratedReport row. A completed item always has a preview; the folder
                        // is only a defensive fallback.
                        $preview = $item->previewAction();

                        return [
                            'label' => $item->typeLabel().($item->businessName ? ' — '.$item->businessName : ''),
                            'icon' => $item->typeIcon(),
                            'url' => $preview['url'] ?? $item->folderUrl(),
                            'method' => $preview['method'] ?? 'GET',
                            'fields' => $preview['fields'] ?? [],
                            'completed' => $item->lastUpdatedAt?->timezone($timezone)->format('M j, Y'),
                        ];
                    })->values()->all(),
                ])->values()->all(),
                'count' => $items->count(),
            ])->values()->all(),
        ];
    }

    /**
     * Completed investigations over time, read from each folder's own `completed_at`. Grouping is
     * done after converting to the display timezone so a late-evening completion is never counted
     * on the following day.
     */
    private function trend(User $user, string $range, CarbonImmutable $now, string $timezone): array
    {
        [$buckets, $format, $labelFormat] = match ($range) {
            '30d' => [$this->dayBuckets($now, 30), 'Y-m-d', 'M j'],
            '12m' => [$this->monthBuckets($now, 12), 'Y-m', 'M Y'],
            default => [$this->dayBuckets($now, 7), 'Y-m-d', 'M j'],
        };

        $completions = $this->scopedFolders($user)
            ->where('status', ClientFolderStatus::Completed)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $buckets->first()->utc())
            ->pluck('completed_at');

        $counts = $completions
            ->map(fn ($completedAt): string => $completedAt->timezone($timezone)->format($format))
            ->countBy();

        $points = $buckets->map(fn (CarbonImmutable $bucket): array => [
            'label' => $bucket->format($labelFormat),
            'value' => (int) ($counts[$bucket->format($format)] ?? 0),
        ])->values();

        return [
            'points' => $points->all(),
            'max' => max(1, (int) $points->max('value')),
            'total' => (int) $points->sum('value'),
        ];
    }

    /** @return Collection<int, CarbonImmutable> */
    private function dayBuckets(CarbonImmutable $now, int $days): Collection
    {
        return collect(range($days - 1, 0))->map(fn (int $offset): CarbonImmutable => $now->startOfDay()->subDays($offset));
    }

    /** @return Collection<int, CarbonImmutable> */
    private function monthBuckets(CarbonImmutable $now, int $months): Collection
    {
        return collect(range($months - 1, 0))->map(fn (int $offset): CarbonImmutable => $now->startOfMonth()->subMonths($offset));
    }

    /**
     * Each bar is completed-over-applicable using that module's authoritative obligation: CI/BI,
     * Residence and CI Activities include required people/work even before a row exists, while a
     * client with no business income source does not create a Business Check obligation. A category
     * with no applicable work reports 0% and says so in the view.
     *
     * @param  Collection<int, int>  $folderIds
     */
    private function activityProgress(Collection $folderIds): array
    {
        $folderCount = $folderIds->count();

        // A CI/BI Report is required once PER PERSON - the Applicant of every folder in scope, plus
        // every existing Co-Maker (MandatoryInvestigationRequirements lists 'cibi' under both). The
        // denominator therefore counts people, not rows: a person whose report has not been created
        // yet is exactly the outstanding work this bar exists to show. Counting existing
        // cibi_reports rows instead made the bar read "11 of 11 = 100%" whenever every report that
        // happened to exist was finished, however many folders had none at all.
        //
        // Generated PDF/Excel output lives in generated_reports and is not consulted here at all.
        $coMakerCount = CoMaker::query()->whereIn('client_folder_id', $folderIds)->count();
        $cibiRequired = $folderCount + $coMakerCount;

        // The numerator follows the identical person-level rule, so it is counted per LOGICAL
        // PERSON rather than per row. A plain row count is NOT safe here, and the composite unique
        // index on (client_folder_id, co_maker_id) does not make it safe: SQL treats NULLs as
        // distinct inside a UNIQUE index, so that index constrains Co-Maker rows but lets one
        // folder hold any number of APPLICANT rows, every one of them with co_maker_id IS NULL.
        // GROUP BY is the opposite - it groups NULLs together - so one group per (folder, person)
        // is exactly the rule this bar needs, and it behaves identically on SQLite, MySQL and
        // PostgreSQL. Counting those groups through a subquery keeps this to a single query with
        // no model hydration.
        //
        // Nothing else can slip in: cibi_reports has no soft deletes, so a deleted report leaves
        // no countable row behind, and co_maker_id is a cascadeOnDelete foreign key, so a report
        // can never outlive the Co-Maker it belongs to and stand for a person no longer in scope.
        $cibiComplete = DB::query()->fromSub(
            CibiReport::query()
                ->whereIn('client_folder_id', $folderIds)
                ->where('state', RecordState::Complete)
                ->groupBy('client_folder_id', 'co_maker_id')
                ->select('client_folder_id', 'co_maker_id')
                ->toBase(),
            'completed_cibi'
        )->count();

        // Residence Check uses the same saved-row completion predicate and exact-person identity as
        // MandatoryInvestigationRequirements: one requirement for every Applicant plus one for
        // every Co-Maker belonging to an in-scope folder. Missing rows remain in the denominator.
        // Grouping nullable co_maker_id deliberately collapses duplicate Applicant rows as well as
        // duplicate Co-Maker rows, while the EXISTS guard prevents a malformed cross-folder
        // co_maker_id from representing a person who is not actually required by that folder.
        $residenceRequired = $folderCount + $coMakerCount;
        $residenceChecked = DB::query()->fromSub(
            ResidenceCheck::query()
                ->whereIn('client_folder_id', $folderIds)
                ->where(fn (Builder $person) => $person
                    ->whereNull('co_maker_id')
                    ->orWhereExists(fn (QueryBuilder $coMaker) => $coMaker
                        ->selectRaw('1')
                        ->from('co_makers')
                        ->whereColumn('co_makers.id', 'residence_checks.co_maker_id')
                        ->whereColumn('co_makers.client_folder_id', 'residence_checks.client_folder_id')))
                ->groupBy('client_folder_id', 'co_maker_id')
                ->select('client_folder_id', 'co_maker_id')
                ->toBase(),
            'completed_residence_checks'
        )->count();

        $businessTotal = IncomeSource::query()->whereIn('client_folder_id', $folderIds)->count();
        $businessChecked = BusinessCheck::query()
            ->whereIn('client_folder_id', $folderIds)
            ->whereNotNull('income_source_id')
            ->distinct()
            ->count('income_source_id');

        $activities = $this->mandatoryActivityProgress($folderIds, $coMakerCount);

        return [
            $this->progressBar('CI/BI Report', $cibiComplete, $cibiRequired, 'CI/BI Reports'),
            $this->progressBar('Residence Check', $residenceChecked, $residenceRequired, 'required Residence Checks'),
            $this->progressBar('Business Check', $businessChecked, $businessTotal, 'businesses'),
            $this->progressBar('CI Activities', $activities['completed'], $activities['total'], 'required activities'),
        ];
    }

    /**
     * Counts mandatory CI Activity obligations, including those with no row yet, using the exact
     * applicability and completion predicates owned by MandatoryInvestigationRequirements.
     * Applicant requirements are Barangay, Neighbor and one Bank / Coop parent; Co-Makers require
     * only Barangay and Neighbor. EXISTS flags make duplicate rows count once and preserve exact
     * Applicant / Co-Maker identity. Asset and every custom activity are outside these maps.
     *
     * @param  Collection<int, int>  $folderIds
     * @return array{completed: int, total: int}
     */
    private function mandatoryActivityProgress(Collection $folderIds, int $coMakerCount): array
    {
        $applicantRequirements = MandatoryInvestigationRequirements::ciActivityRequirements(null);
        $coMakerRequirements = MandatoryInvestigationRequirements::ciActivityRequirements('co_makers.id');
        $total = ($folderIds->count() * count($applicantRequirements))
            + ($coMakerCount * count($coMakerRequirements));

        if ($folderIds->isEmpty()) {
            return ['completed' => 0, 'total' => $total];
        }

        $flag = fn (string $requirement, ?string $coMakerColumn) => fn (QueryBuilder $record) => MandatoryInvestigationRequirements::recordQuery($record, $requirement, $coMakerColumn)->selectRaw('1')->limit(1);
        $sumFlags = fn (Collection $people, array $requirements): int => (int) $people->sum(
            fn (object $person): int => collect(array_keys($requirements))->sum(
                fn (string $requirement): int => (int) (bool) $person->{'met_'.$requirement}
            )
        );

        $applicants = DB::table('client_folders')
            ->whereIn('client_folders.id', $folderIds)
            ->select('client_folders.id');
        foreach (array_keys($applicantRequirements) as $requirement) {
            $applicants->selectSub($flag($requirement, null), 'met_'.$requirement);
        }

        $coMakers = DB::table('co_makers')
            ->join('client_folders', 'client_folders.id', '=', 'co_makers.client_folder_id')
            ->whereIn('client_folders.id', $folderIds)
            ->select('co_makers.id');
        foreach (array_keys($coMakerRequirements) as $requirement) {
            $coMakers->selectSub($flag($requirement, 'co_makers.id'), 'met_'.$requirement);
        }

        return [
            'completed' => $sumFlags($applicants->get(), $applicantRequirements)
                + $sumFlags($coMakers->get(), $coMakerRequirements),
            'total' => $total,
        ];
    }

    private function progressBar(string $label, int $completed, int $applicable, string $unit): array
    {
        return [
            'label' => $label,
            'completed' => $completed,
            'applicable' => $applicable,
            'unit' => $unit,
            'percent' => $this->percent($completed, $applicable),
        ];
    }

    /**
     * THE In Progress rule, in one place: investigation work has actually STARTED but is not yet
     * finished - mandatory progress strictly between 0% and 100%. Every reader of "In Progress"
     * (the KPI, its detail modal and the Workload chart's slice) calls this, so the three can never
     * describe different folder sets.
     *
     * It compares `completed` against `total` rather than the rounded `percent`, which is the same
     * question asked exactly: a folder with one requirement met out of a very long list would round
     * to 0%, and one with a single requirement left would round to 100%, yet neither has actually
     * started-but-finished or finished. The display percentage stays exactly as it was.
     *
     * A 0% folder is deliberately NOT In Progress: being active, incomplete, or merely holding
     * auto-generated pristine activity rows is not the same as having started. Those folders are
     * the Workload chart's existing Pending slice.
     *
     * @param  array{completed: int, total: int, percent: int, missing: list<string>}|null  $progress
     */
    private function isInProgress(?array $progress): bool
    {
        return $progress !== null && $progress['completed'] > 0 && $progress['missing'] !== [];
    }

    private function percent(int $value, int $total): int
    {
        return $total > 0 ? (int) round($value / $total * 100) : 0;
    }

    /**
     * The one personal section: still-open CI activities THIS user is responsible for, overdue ones
     * first, then the ones already scheduled or being followed up, then the longest untouched. Folder
     * access is collaborative but activity responsibility is not - an activity another investigator
     * created stays on their list, not this one, which is the same creator-based rule the scheduled
     * reminder feed already uses. The folder filter keeps the list inside what the user may access.
     *
     * @param  Collection<int, int>  $folderIds
     */
    private function workToday(User $user, Collection $folderIds, CarbonImmutable $now, int $requestedPage, string $trendRange): LengthAwarePaginator
    {
        $activities = CiActivity::query()
            ->whereIn('client_folder_id', $folderIds)
            ->where('creator_id', $user->id)
            ->where('status', '!=', ActivityStatus::Completed)
            ->with([
                'clientFolder:id,display_name',
                'coMaker:id,full_name',
                'definition:id,name,code',
                'bankTargets' => fn ($query) => $query->where('status', '!=', ActivityStatus::Completed)->oldest('updated_at'),
                'assetTargets' => fn ($query) => $query->where('status', '!=', ActivityStatus::Completed)->oldest('updated_at'),
            ])
            ->get()
            ->flatMap(function (CiActivity $activity) use ($now): array {
                $code = $activity->definition?->code;
                $personParams = ActivePersonResolver::queryParamsForId($activity->co_maker_id);

                if ($code === ActivityDefinition::BANK_COOP_CHECK_CODE) {
                    $url = route('client-folders.activities.bank-coop.show', [$activity->client_folder_id, $activity->id] + $personParams);

                    return $activity->bankTargets->isEmpty()
                        ? [$this->workTodayItem($activity, $activity, $url, 'bank', null, $now)]
                        : $activity->bankTargets->map(fn (CiActivityBankTarget $target): array => $this->workTodayItem(
                            $activity,
                            $target,
                            $url,
                            'bank',
                            $target->institution_name.($target->branch_location ? ' — '.$target->branch_location : ''),
                            $now,
                            $target->id,
                            $target->inquiry_type,
                        ))->all();
                }

                if ($code === ActivityDefinition::ASSET_CHECK_CODE) {
                    $url = route('client-folders.activities.asset-check.show', [$activity->client_folder_id, $activity->id] + $personParams);

                    return $activity->assetTargets->isEmpty()
                        ? [$this->workTodayItem($activity, $activity, $url, 'asset', null, $now)]
                        : $activity->assetTargets->map(fn (CiActivityAssetTarget $target): array => $this->workTodayItem(
                            $activity,
                            $target,
                            $url,
                            'asset',
                            $target->assessorLabel().' — '.$target->office_location,
                            $now,
                            $target->id,
                        ))->all();
                }

                $isDefaultCheck = in_array($code, ActivityDefinition::MANDATORY_DEFAULT_CODES, true);
                $url = route(
                    $isDefaultCheck ? 'client-folders.activities.default-check.show' : 'client-folders.activities.edit',
                    [$activity->client_folder_id, $activity->id] + $personParams,
                );

                return [$this->workTodayItem($activity, $activity, $url, $isDefaultCheck ? 'default' : null, null, $now)];
            })
            ->sort(function (array $left, array $right): int {
                return [$left['sort_priority'], $left['sort_schedule'], $left['sort_updated']]
                    <=> [$right['sort_priority'], $right['sort_schedule'], $right['sort_updated']];
            })
            ->map(function (array $item): array {
                unset($item['sort_priority'], $item['sort_schedule'], $item['sort_updated']);

                return $item;
            })
            ->values();

        $lastPage = max(1, (int) ceil($activities->count() / self::WORK_PAGE_SIZE));
        $page = min($requestedPage, $lastPage);

        return new LengthAwarePaginator(
            $activities->forPage($page, self::WORK_PAGE_SIZE)->values(),
            $activities->count(),
            self::WORK_PAGE_SIZE,
            $page,
            [
                'path' => route('home'),
                'query' => ['range' => $trendRange],
                'pageName' => 'work_page',
            ],
        );
    }

    /**
     * @param  CiActivity|CiActivityBankTarget|CiActivityAssetTarget  $work  Parent activity for
     *                                                                       regular/default checks, or the exact target for target-derived checks.
     */
    private function workTodayItem(
        CiActivity $activity,
        CiActivity|CiActivityBankTarget|CiActivityAssetTarget $work,
        string $url,
        ?string $modalKind,
        ?string $target,
        CarbonImmutable $now,
        ?int $targetId = null,
        ?string $targetType = null,
    ): array {
        $isOverdue = $this->isOverdue($work, $now);
        $status = $work->status;
        $directCompletion = $isOverdue && (($modalKind === 'default'
            && in_array($activity->definition?->code, [
                ActivityDefinition::BARANGAY_CHECK_CODE,
                ActivityDefinition::NEIGHBOR_CHECK_CODE,
            ], true))
            || ($modalKind === 'asset' && $work instanceof CiActivityAssetTarget)
            || ($modalKind === 'bank' && $work instanceof CiActivityBankTarget));
        $modalUrl = $modalKind === null ? null : $url.((str_contains($url, '?')) ? '&' : '?').http_build_query([
            'dashboard_modal' => 1,
            'dashboard_kind' => $modalKind,
            'dashboard_target_id' => $targetId,
        ]);

        $clientUrl = route(
            'client-folders.activities.index',
            [$activity->client_folder_id] + ActivePersonResolver::queryParamsForId($activity->co_maker_id),
        ).'#activity-'.$activity->id;

        return [
            'id' => $activity->id,
            'target_id' => $targetId,
            'target_type' => $targetType,
            'client' => $activity->clientFolder?->display_name ?? 'Unnamed client',
            'client_url' => $clientUrl,
            'person' => $activity->coMaker?->full_name,
            'activity' => $activity->name,
            'target' => $target,
            'status' => $isOverdue ? 'Overdue' : $status->label(),
            'status_value' => $status->value,
            'tone' => $isOverdue ? 'danger' : match ($status) {
                ActivityStatus::FollowUp => 'amber',
                ActivityStatus::Scheduled => 'brand',
                default => 'neutral',
            },
            'updated_at' => $work->updated_at,
            'url' => $isOverdue ? $url : $clientUrl,
            'modal_url' => $modalUrl,
            'modal_kind' => $modalKind,
            'direct_completion' => $directCompletion,
            'completion_modal_id' => match (true) {
                $directCompletion && $modalKind === 'asset' => 'dashboard-overdue-asset-complete-modal',
                $directCompletion && $modalKind === 'bank' => 'dashboard-overdue-bank-complete-modal',
                default => 'dashboard-overdue-complete-activity-modal',
            },
            'completion_target' => $directCompletion && in_array($modalKind, ['asset', 'bank'], true) ? $target : null,
            'completion_target_type' => $directCompletion && $work instanceof CiActivityBankTarget ? $work->inquiryTypeLabel() : null,
            'completion_method' => $directCompletion && in_array($modalKind, ['asset', 'bank'], true) ? 'PATCH' : 'PUT',
            'completion_url' => $directCompletion
                ? match ($modalKind) {
                    'asset' => route('client-folders.activities.asset-targets.complete', [$activity->client_folder_id, $activity->id, $work->id]),
                    'bank' => route('client-folders.activities.bank-targets.complete', [$activity->client_folder_id, $activity->id, $work->id]),
                    default => route('client-folders.activities.update', [$activity->client_folder_id, $activity->id]),
                }
                : null,
            'completion_co_maker_id' => $directCompletion ? $activity->co_maker_id : null,
            'completion_expected_updated_at' => $directCompletion ? $work->updated_at->toISOString() : null,
            'completion_schedule' => $directCompletion && $work->scheduled_at
                ? $work->scheduled_at->timezone(config('cims.display_timezone'))->format('M j, Y').' · '.($work->scheduled_has_time ? $work->scheduled_at->timezone(config('cims.display_timezone'))->format('g:i A') : 'No specific time')
                : null,
            'completion_remarks' => $directCompletion ? $work->remarks : null,
            'action' => $isOverdue ? 'Continue' : 'Open',
            'sort_priority' => match (true) {
                $isOverdue => 0,
                $status === ActivityStatus::Scheduled => 1,
                $status === ActivityStatus::FollowUp => 2,
                $status === ActivityStatus::Pending => 3,
                default => 4,
            },
            'sort_schedule' => $work->scheduled_at?->getTimestamp() ?? PHP_INT_MAX,
            'sort_updated' => $work->updated_at?->getTimestamp() ?? 0,
        ];
    }

    /**
     * The existing AuditLog, scoped to the same folders, rendered through the audit vocabulary
     * ClientFolderOverview already owns — this deliberately does not introduce a second history.
     *
     * The card still receives only the three newest entries. The complete mapped collection is
     * retained solely for the established View All modal, avoiding the incorrect Client Folders
     * navigation without introducing another route or another database query.
     *
     * @param  Collection<int, int>  $folderIds
     * @return array{events: array<int, array{label: string, icon: string, client: string, user: ?string, at: ?CarbonImmutable}>, hasMore: bool, allEvents: array<int, array{label: string, icon: string, client: string, user: ?string, at: ?CarbonImmutable}>}
     */
    private function recentActivity(Collection $folderIds, string $timezone): array
    {
        $events = AuditLog::query()
            ->whereIn('client_folder_id', $folderIds)
            ->with(['clientFolder:id,display_name', 'user:id,full_name'])
            ->latest('created_at')
            ->latest('id')
            ->get(['id', 'user_id', 'client_folder_id', 'action', 'metadata', 'created_at']);

        $mappedEvents = $events
            ->map(function (AuditLog $event) use ($timezone): array {
                $definition = ClientFolderOverview::activityLabel($event->action);
                $metadata = (array) $event->metadata;

                return [
                    'label' => str_starts_with($event->action, 'ci_activity.')
                        ? CiActivityHistoryFeed::labelFor($event->action, $metadata)
                        : $definition['label'],
                    'icon' => $definition['icon'],
                    'client' => $event->clientFolder?->display_name ?? 'Unnamed client',
                    'user' => $event->user?->full_name,
                    'at' => $event->created_at?->timezone($timezone),
                ];
            })
            ->values();

        return [
            'events' => $mappedEvents
                ->take(self::RECENT_ACTIVITY_LIMIT)
                ->values()
                ->all(),
            'hasMore' => $events->count() > self::RECENT_ACTIVITY_LIMIT,
            'allEvents' => $mappedEvents->all(),
        ];
    }
}
