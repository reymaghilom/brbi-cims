<?php

namespace App\Http\Controllers;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Services\ClientFolders\CiActivityScheduleSummary;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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

    // The Activity Type filter must only ever offer the current, genuinely supported operational
    // types — never every ActivityDefinition row, which also includes obsolete/test custom_
    // definitions (e.g. one-off types a CI typed once, like "sas" or "test") that are still
    // is_active for historical CiActivity rows to keep referencing. Identity is ActivityDefinition
    // ::code (never name/label/id), so this list is built from the model's own canonical code
    // constants rather than a second hardcoded string list.
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
            ->select(['id', 'name', 'code'])
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('code', 'like', ActivityDefinition::CUSTOM_CODE_PREFIX.'%'))
            ->orderBy('sort_order')
            ->get();

        // A separate, narrower set purely for the user-facing Activity Type filter dropdown —
        // $definitions above stays the broader row-scoping source (whereIn below) so existing/
        // historical activities under any active definition, custom types included, remain visible
        // in the worklist itself; only the filter's own selectable options are restricted.
        $filterableDefinitions = $definitions
            ->whereIn('code', self::FILTERABLE_ACTIVITY_TYPE_CODES)
            ->sortBy('sort_order')
            ->values();

        $activityType = $request->query('activity_type');
        $activityType = $filterableDefinitions->pluck('code')->contains($activityType) ? $activityType : null;

        $query = CiActivity::query()
            ->whereHas('clientFolder')
            ->whereIn('activity_definition_id', $definitions->pluck('id'))
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

        $rows = $query->get()->map(fn (CiActivity $activity): object => $this->buildRow($activity, $timezone, $today));

        $counts = [
            'pending' => $rows->filter(fn ($row) => $row->statusValue === ActivityStatus::Pending->value)->count(),
            'scheduled' => $rows->filter(fn ($row) => $row->statusValue === ActivityStatus::Scheduled->value)->count(),
            'follow_up' => $rows->filter(fn ($row) => $row->statusValue === ActivityStatus::FollowUp->value)->count(),
            'completed' => $rows->filter(fn ($row) => $row->statusValue === ActivityStatus::Completed->value)->count(),
            'due_today' => $rows->filter(fn ($row) => $row->isDueToday)->count(),
            'overdue' => $rows->filter(fn ($row) => $row->isOverdue)->count(),
            'all' => $rows->count(),
        ];

        $visibleRows = $this->applyScheduleFilter($rows, $schedule, $timezone, $today);
        $visibleRows = $this->applyTabFilter($visibleRows, $tab);
        $visibleRows = $this->sortRows($visibleRows, $sort);

        $page = max(1, (int) $request->query('page', 1));
        $activitiesPaginator = new LengthAwarePaginator(
            $visibleRows->forPage($page, $perPage)->values(),
            $visibleRows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

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

    private function applyScheduleFilter(Collection $rows, string $schedule, string $timezone, Carbon $today): Collection
    {
        if ($schedule === 'all') {
            return $rows;
        }

        $localToday = $today->copy()->startOfDay();

        return $rows->filter(function (object $row) use ($schedule, $timezone, $localToday): bool {
            if (! $row->scheduledAt) {
                return false;
            }

            $localScheduleDate = $row->scheduledAt->copy()->timezone($timezone)->startOfDay();

            return match ($schedule) {
                'today' => $localScheduleDate->isSameDay($localToday),
                'tomorrow' => $localScheduleDate->isSameDay($localToday->copy()->addDay()),
                'this_week' => $localScheduleDate->between($localToday->copy()->startOfWeek(), $localToday->copy()->endOfWeek()),
                'overdue' => $row->isOverdue,
                default => true,
            };
        })->values();
    }

    private function applyTabFilter(Collection $rows, string $tab): Collection
    {
        return match ($tab) {
            'due_today' => $rows->filter(fn ($row) => $row->isDueToday)->values(),
            'scheduled' => $rows->filter(fn ($row) => $row->statusValue === ActivityStatus::Scheduled->value)->values(),
            'follow_up' => $rows->filter(fn ($row) => $row->statusValue === ActivityStatus::FollowUp->value)->values(),
            'completed' => $rows->filter(fn ($row) => $row->statusValue === ActivityStatus::Completed->value)->values(),
            default => $rows,
        };
    }

    private function sortRows(Collection $rows, string $sort): Collection
    {
        return match ($sort) {
            'latest_schedule' => $rows->sortByDesc(fn ($row) => $row->scheduledAt?->timestamp ?? -INF)->values(),
            'recently_updated' => $rows->sortByDesc(fn ($row) => $row->updatedAt?->timestamp ?? 0)->values(),
            'client_name' => $rows->sortBy(fn ($row) => $row->clientFolder->display_name)->values(),
            default => $rows->sortBy(fn ($row) => $row->scheduledAt?->timestamp ?? INF)->values(),
        };
    }
}
