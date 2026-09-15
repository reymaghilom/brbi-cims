<?php

namespace App\Http\Controllers;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Services\ClientFolders\CiActivityScheduleSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Global, cross-client CI Activities worklist. Read-only: this page never edits an activity —
 * it only surfaces, searches, filters, sorts, and links out to the exact Client Folder / Applicant
 * or Co-Maker / CI Activity context where editing already lives (see CiActivityController).
 *
 * Reuses CiActivityScheduleSummary as the single source of truth for Bank/Asset target-derived
 * schedules, exactly like the per-client tracker table — this page must never reinterpret that logic.
 */
class GlobalCiActivityController extends Controller
{
    private const STATUS_FILTERS = ['pending', 'scheduled', 'follow_up', 'completed'];

    private const PERSON_FILTERS = ['applicant', 'co_maker'];

    private const SCHEDULE_FILTERS = ['today', 'tomorrow', 'this_week', 'overdue'];

    private const SORTS = ['earliest_schedule', 'latest_schedule', 'recently_updated', 'client_name'];

    private const PER_PAGE_OPTIONS = [5, 10, 20, 50];

    private const TABS = ['all', 'due_today', 'scheduled', 'follow_up', 'completed'];

    // The canonical half of the Activity Type filter. Identity is ActivityDefinition::code
    // (never name/label/id), so this list is built from the model's own canonical code constants
    // rather than a second hardcoded string list. User-created (custom_) definitions are added
    // to it dynamically — see $filterableDefinitions — so a new Activity Type becomes filterable
    // here without any code change.
    private const FILTERABLE_ACTIVITY_TYPE_CODES = [
        ActivityDefinition::BARANGAY_CHECK_CODE,
        ActivityDefinition::NEIGHBOR_CHECK_CODE,
        ActivityDefinition::BANK_COOP_CHECK_CODE,
        ActivityDefinition::ASSET_CHECK_CODE,
    ];

    public function __invoke(Request $request): View
    {
        Gate::authorize('viewAny', CiActivity::class);

        $timezone = config('cims.display_timezone');
        $today = now($timezone);

        $status = $request->query('status');
        $status = in_array($status, self::STATUS_FILTERS, true) ? $status : null;

        $person = $request->query('person');
        $person = in_array($person, self::PERSON_FILTERS, true) ? $person : null;

        $schedule = $request->query('schedule');
        $schedule = in_array($schedule, self::SCHEDULE_FILTERS, true) ? $schedule : 'all';

        $search = trim((string) $request->query('search', ''));

        $sort = $request->query('sort');
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'earliest_schedule';

        $tab = $request->query('tab');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'all';

        $perPage = (int) $request->query('per_page', 5);
        $perPage = in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : 5;

        $definitions = ActivityDefinition::query()
            ->select(['id', 'name', 'code', 'is_active', 'sort_order'])
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('code', 'like', ActivityDefinition::CUSTOM_CODE_PREFIX.'%'))
            ->orderBy('sort_order')
            ->get();

        // The user-facing Activity Type filter: the canonical operational types plus every
        // still-active user-created (custom_) definition, taken straight from the authoritative
        // ActivityDefinition rows — so a newly created Activity Type is filterable on the next
        // render with no code change, and a removed (deactivated) one stops being selectable.
        // $definitions above stays the broader row-scoping source (whereIn below) so historical
        // activities under a retired custom definition remain visible in the worklist itself.
        $filterableDefinitions = $definitions
            ->filter(fn (ActivityDefinition $definition): bool => in_array($definition->code, self::FILTERABLE_ACTIVITY_TYPE_CODES, true)
                || ($definition->isCustom() && $definition->is_active))
            ->sortBy('sort_order')
            ->values();

        $activityType = $request->query('activity_type');
        $activityType = $filterableDefinitions->pluck('code')->contains($activityType) ? $activityType : null;

        $query = CiActivity::query()
            ->whereHas('clientFolder')
            ->whereIn('activity_definition_id', $definitions->pluck('id'));

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($activityType !== null) {
            $query->whereHas('definition', fn ($definitionQuery) => $definitionQuery->where('code', $activityType));
        }

        if ($person === 'applicant') {
            $query->whereNull('co_maker_id');
        } elseif ($person === 'co_maker') {
            $query->whereNotNull('co_maker_id');
        }

        if ($search !== '') {
            $query->where(function ($searchQuery) use ($search): void {
                $searchQuery
                    ->whereHas('clientFolder', fn ($folders) => $folders->where('display_name', 'like', "%{$search}%"))
                    ->orWhereHas('coMaker', fn ($coMakers) => $coMakers->where('full_name', 'like', "%{$search}%"))
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhereHas('definition', fn ($definitionQuery) => $definitionQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('bankTargets', fn ($targets) => $targets->where('institution_name', 'like', "%{$search}%"))
                    ->orWhereHas('assetTargets', fn ($targets) => $targets->where('office_location', 'like', "%{$search}%"));
            });
        }

        // Everything that decides which activities belong to a page runs in SQL, so only the
        // requested page is ever hydrated. The effective schedule mirrors buildRow() exactly: Bank/
        // Asset checks use their earliest currently-Scheduled target (CiActivityScheduleSummary's
        // rule), every other activity its own scheduled_at. Local-day comparisons use the display
        // timezone's day boundaries expressed in the storage timezone, the same instants buildRow()
        // compares after converting each stored value.
        $effectiveSchedule = $this->effectiveScheduleSql($query, $definitions);
        $activeStatusSql = $this->activeStatusSql($query);
        $localToday = $today->copy()->startOfDay();
        $todayStart = $this->storageBoundary($localToday);
        $tomorrowStart = $this->storageBoundary($localToday->copy()->addDay());

        $countsRow = (clone $query)->toBase()->selectRaw(
            'COUNT(*) as all_count'
            .', SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count'
            .', SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as scheduled_count'
            .', SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as follow_up_count'
            .', SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_count'
            ." , SUM(CASE WHEN {$activeStatusSql} AND ({$effectiveSchedule}) >= ? AND ({$effectiveSchedule}) < ? THEN 1 ELSE 0 END) as due_today_count"
            ." , SUM(CASE WHEN {$activeStatusSql} AND ({$effectiveSchedule}) < ? THEN 1 ELSE 0 END) as overdue_count",
            [
                ActivityStatus::Pending->value, ActivityStatus::Scheduled->value, ActivityStatus::FollowUp->value, ActivityStatus::Completed->value,
                $todayStart, $tomorrowStart, $todayStart,
            ],
        )->first();

        $counts = [
            'pending' => (int) ($countsRow->pending_count ?? 0),
            'scheduled' => (int) ($countsRow->scheduled_count ?? 0),
            'follow_up' => (int) ($countsRow->follow_up_count ?? 0),
            'completed' => (int) ($countsRow->completed_count ?? 0),
            'due_today' => (int) ($countsRow->due_today_count ?? 0),
            'overdue' => (int) ($countsRow->overdue_count ?? 0),
            'all' => (int) ($countsRow->all_count ?? 0),
        ];

        $this->applyScheduleFilter($query, $schedule, $effectiveSchedule, $activeStatusSql, $localToday);
        $this->applyTabFilter($query, $tab, $effectiveSchedule, $activeStatusSql, $todayStart, $tomorrowStart);
        $this->applySort($query, $sort, $effectiveSchedule);

        $query
            ->with([
                'definition:id,name,code',
                'clientFolder' => fn ($folders) => $folders
                    ->select(['id', 'folder_number', 'display_name'])
                    ->with(['addresses' => fn ($addresses) => $addresses->where('is_primary', true)->limit(1)]),
                'coMaker:id,full_name',
                'bankTargets' => fn ($targets) => $targets
                    ->where('status', ActivityStatus::Scheduled->value)
                    ->whereNotNull('scheduled_at')
                    ->select(['id', 'ci_activity_id', 'institution_name', 'branch_location', 'status', 'scheduled_at', 'scheduled_has_time']),
                'assetTargets' => fn ($targets) => $targets
                    ->where('status', ActivityStatus::Scheduled->value)
                    ->whereNotNull('scheduled_at')
                    ->select(['id', 'ci_activity_id', 'assessor_type', 'office_location', 'status', 'scheduled_at', 'scheduled_has_time']),
            ])
            ->withCount([
                'bankTargets',
                'bankTargets as completed_bank_targets_count' => fn ($targets) => $targets->where('status', ActivityStatus::Completed->value),
                'assetTargets',
                'assetTargets as completed_asset_targets_count' => fn ($targets) => $targets->where('status', ActivityStatus::Completed->value),
            ]);

        $page = max(1, (int) $request->query('page', 1));
        $activitiesPaginator = $query
            ->paginate($perPage, ['*'], 'page', $page)
            ->withPath($request->url())
            ->appends($request->query())
            ->through(fn (CiActivity $activity): object => $this->buildRow($activity, $timezone, $today));

        $data = [
            'rows' => $activitiesPaginator,
            'counts' => $counts,
            'definitions' => $filterableDefinitions,
            'filters' => [
                'status' => $status ?? '',
                'activity_type' => $activityType ?? '',
                'person' => $person ?? '',
                'schedule' => $schedule,
                'search' => $search,
                'sort' => $sort,
                'tab' => $tab,
                'per_page' => $perPage,
            ],
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ];

        // A pagination click asks for the worklist alone, so the header, tabs, counts and filter
        // toolbar are neither re-rendered nor recomputed for the user. Same authoritative query.
        if ($request->ajax()) {
            return view('ci-activities._listing', $data);
        }

        return view('ci-activities.index', $data);
    }

    private function buildRow(CiActivity $activity, string $timezone, Carbon $today): object
    {
        $definitionCode = $activity->definition->code;
        $isBankCoopCheck = $definitionCode === ActivityDefinition::BANK_COOP_CHECK_CODE;
        $isAssetCheck = $definitionCode === ActivityDefinition::ASSET_CHECK_CODE;
        $isMandatoryDefault = $activity->isMandatoryDefault();

        $scheduleSummary = ($isBankCoopCheck || $isAssetCheck)
            ? CiActivityScheduleSummary::fromCurrentTargets($isBankCoopCheck ? $activity->bankTargets : $activity->assetTargets)
            : null;

        $scheduledAt = $scheduleSummary ? $scheduleSummary->scheduled_at : $activity->scheduled_at;
        $scheduledHasTime = $scheduleSummary ? $scheduleSummary->scheduled_has_time : (bool) $activity->scheduled_has_time;

        $isDueToday = false;
        $isOverdue = false;
        $overdueDays = null;
        $activeStatus = in_array($activity->status, [ActivityStatus::Scheduled, ActivityStatus::FollowUp], true);

        if ($scheduledAt && $activeStatus) {
            $localScheduleDate = $scheduledAt->copy()->timezone($timezone)->startOfDay();
            $localToday = $today->copy()->startOfDay();
            $isDueToday = $localScheduleDate->isSameDay($localToday);
            $isOverdue = $localScheduleDate->lt($localToday);
            $overdueDays = $isOverdue ? $localToday->diffInDays($localScheduleDate) : null;
        }

        if ($isBankCoopCheck) {
            $progressDenominator = $activity->bank_targets_count;
            $progressNumerator = $activity->completed_bank_targets_count;
        } elseif ($isAssetCheck) {
            $progressDenominator = $activity->asset_targets_count;
            $progressNumerator = $activity->completed_asset_targets_count;
        } else {
            $progressDenominator = 1;
            $progressNumerator = $activity->status === ActivityStatus::Completed ? 1 : 0;
        }
        $progressPercent = $progressDenominator > 0 ? (int) round(($progressNumerator / $progressDenominator) * 100) : 0;

        $isCoMaker = $activity->co_maker_id !== null;
        $personName = $isCoMaker ? $activity->coMaker?->full_name : $activity->clientFolder->display_name;

        $targetLabel = null;
        if ($isBankCoopCheck || $isAssetCheck) {
            $targetLabel = $scheduleSummary->primary_label;
        } elseif ($isMandatoryDefault) {
            $primaryAddress = $activity->clientFolder->relationLoaded('addresses') ? $activity->clientFolder->addresses->first() : null;
            if ($primaryAddress && (filled($primaryAddress->barangay) || filled($primaryAddress->city_municipality))) {
                $targetLabel = collect([$primaryAddress->barangay, $primaryAddress->city_municipality])->filter()->implode(', ');
            }
        }

        return (object) [
            'activity' => $activity,
            'clientFolder' => $activity->clientFolder,
            'isBankCoopCheck' => $isBankCoopCheck,
            'isAssetCheck' => $isAssetCheck,
            'scheduleSummary' => $scheduleSummary,
            'scheduledAt' => $scheduledAt,
            'scheduledHasTime' => $scheduledHasTime,
            'isDueToday' => $isDueToday,
            'isOverdue' => $isOverdue,
            'overdueDays' => $overdueDays,
            'progressNumerator' => $progressNumerator,
            'progressDenominator' => $progressDenominator,
            'progressPercent' => $progressPercent,
            'isCoMaker' => $isCoMaker,
            'personLabel' => $isCoMaker ? 'Co-Maker' : 'Applicant',
            'personName' => $personName,
            'activityLabel' => $activity->definition->name,
            'targetLabel' => $targetLabel,
            'additionalScheduledCount' => $scheduleSummary->additional_count ?? 0,
            'statusValue' => $activity->status->value,
            'statusLabel' => $activity->status->label(),
            'updatedAt' => $activity->updated_at,
            'openUrl' => $this->openUrl($activity),
        ];
    }

    /**
     * Always the exact Client Folder's own CI Activities table — in the exact Applicant/Co-Maker
     * context — never a type-specific tracker/modal URL (Bank/Coop, Asset, default-check), so
     * "Open" never auto-launches an unrelated modal on landing. The #activity-{id} fragment lets
     * the browser's own native anchor-scroll bring the exact row into view without any additional
     * routing/highlight system.
     */
    private function openUrl(CiActivity $activity): string
    {
        $clientFolder = $activity->clientFolder;
        $personParams = $activity->co_maker_id ? ['person' => 'co-maker', 'co_maker_id' => $activity->co_maker_id] : [];

        return route('client-folders.activities.index', [$clientFolder] + $personParams).'#activity-'.$activity->id;
    }

    /**
     * SQL twin of buildRow()'s $scheduledAt. Only integer definition ids, a fixed enum value and
     * grammar-wrapped identifiers are interpolated — never request input.
     */
    private function effectiveScheduleSql(Builder $query, Collection $definitions): string
    {
        $grammar = $query->getQuery()->getGrammar();
        $activityTable = (new CiActivity)->getTable();
        $activityId = $grammar->wrap($activityTable.'.id');
        $ownSchedule = $grammar->wrap($activityTable.'.scheduled_at');
        $definitionId = $grammar->wrap($activityTable.'.activity_definition_id');
        $scheduled = ActivityStatus::Scheduled->value;

        $cases = collect([
            ActivityDefinition::BANK_COOP_CHECK_CODE => (new CiActivityBankTarget)->getTable(),
            ActivityDefinition::ASSET_CHECK_CODE => (new CiActivityAssetTarget)->getTable(),
        ])->map(function (string $targetTable, string $code) use ($definitions, $grammar, $activityId, $definitionId, $scheduled): ?string {
            $ids = $definitions->where('code', $code)->pluck('id')->map(fn ($id): int => (int) $id);
            if ($ids->isEmpty()) {
                return null;
            }

            $targetSchedule = $grammar->wrap($targetTable.'.scheduled_at');

            return "WHEN {$definitionId} IN ({$ids->implode(',')}) THEN (SELECT MIN({$targetSchedule}) FROM {$grammar->wrapTable($targetTable)}"
                ." WHERE {$grammar->wrap($targetTable.'.ci_activity_id')} = {$activityId}"
                ." AND {$grammar->wrap($targetTable.'.status')} = '{$scheduled}'"
                ." AND {$targetSchedule} IS NOT NULL)";
        })->filter();

        return $cases->isEmpty() ? $ownSchedule : 'CASE '.$cases->implode(' ')." ELSE {$ownSchedule} END";
    }

    /** SQL twin of buildRow()'s $activeStatus (Scheduled or Follow-up) — fixed enum values only. */
    private function activeStatusSql(Builder $query): string
    {
        $status = $query->getQuery()->getGrammar()->wrap((new CiActivity)->getTable().'.status');

        return "{$status} IN ('".ActivityStatus::Scheduled->value."', '".ActivityStatus::FollowUp->value."')";
    }

    /** A display-timezone instant, formatted the way stored datetimes are read back (storage timezone). */
    private function storageBoundary(Carbon $local): string
    {
        return $local->copy()->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function applyScheduleFilter(Builder $query, string $schedule, string $effectiveSchedule, string $activeStatusSql, Carbon $localToday): void
    {
        [$from, $until] = match ($schedule) {
            'today' => [$localToday, $localToday->copy()->addDay()],
            'tomorrow' => [$localToday->copy()->addDay(), $localToday->copy()->addDays(2)],
            'this_week' => [$localToday->copy()->startOfWeek(), $localToday->copy()->endOfWeek()->startOfDay()->addDay()],
            'overdue' => [null, $localToday],
            default => [null, null],
        };

        if ($schedule === 'all' || $until === null) {
            return;
        }

        if ($schedule === 'overdue') {
            $query->whereRaw("{$activeStatusSql} AND ({$effectiveSchedule}) < ?", [$this->storageBoundary($until)]);

            return;
        }

        $query->whereRaw(
            "({$effectiveSchedule}) >= ? AND ({$effectiveSchedule}) < ?",
            [$this->storageBoundary($from), $this->storageBoundary($until)],
        );
    }

    private function applyTabFilter(Builder $query, string $tab, string $effectiveSchedule, string $activeStatusSql, string $todayStart, string $tomorrowStart): void
    {
        match ($tab) {
            'due_today' => $query->whereRaw("{$activeStatusSql} AND ({$effectiveSchedule}) >= ? AND ({$effectiveSchedule}) < ?", [$todayStart, $tomorrowStart]),
            'scheduled' => $query->where('status', ActivityStatus::Scheduled->value),
            'follow_up' => $query->where('status', ActivityStatus::FollowUp->value),
            'completed' => $query->where('status', ActivityStatus::Completed->value),
            default => null,
        };
    }

    /**
     * Same orderings as before, nulls last where they were, with id as the deterministic tie-break
     * the previous stable in-memory sort inherited from the unordered fetch.
     */
    private function applySort(Builder $query, string $sort, string $effectiveSchedule): void
    {
        $activityTable = (new CiActivity)->getTable();

        match ($sort) {
            'latest_schedule' => $query
                ->orderByRaw("CASE WHEN ({$effectiveSchedule}) IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw("({$effectiveSchedule}) DESC"),
            'recently_updated' => $query
                ->orderByRaw('CASE WHEN '.$query->getQuery()->getGrammar()->wrap($activityTable.'.updated_at').' IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc($activityTable.'.updated_at'),
            'client_name' => $query->orderBy(
                ClientFolder::query()->select('display_name')->whereColumn((new ClientFolder)->getTable().'.id', $activityTable.'.client_folder_id')->limit(1),
            ),
            default => $query
                ->orderByRaw("CASE WHEN ({$effectiveSchedule}) IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw("({$effectiveSchedule}) ASC"),
        };

        $query->orderBy($activityTable.'.id');
    }
}
