<?php

namespace App\Services\Dashboard;

use App\Enums\ActivityStatus;
use App\Enums\ClientFolderStatus;
use App\Enums\GenerationStatus;
use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\CiActivity;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\GeneratedReport;
use App\Models\IncomeSource;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\ClientFolders\ClientFolderOverview;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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

    private const WORK_LIST_LIMIT = 6;

    private const RECENT_ACTIVITY_LIMIT = 3;

    public function for(User $user, ?string $trendRange = null): array
    {
        $trendRange = array_key_exists((string) $trendRange, self::TREND_RANGES) ? (string) $trendRange : self::DEFAULT_TREND_RANGE;
        $timezone = (string) config('cims.display_timezone');
        $now = CarbonImmutable::now($timezone);

        $folders = $this->scopedFolders($user)->get(['id', 'status', 'completed_at']);
        $folderIds = $folders->pluck('id');

        $workload = $this->workload($folders, $folderIds, $now);
        $recentActivity = $this->recentActivity($folderIds, $timezone);
        $trends = collect(self::TREND_RANGES)
            ->map(fn (string $label, string $key): array => $this->trend($user, $key, $now, $timezone))
            ->all();

        return [
            'greeting' => $this->greeting($now),
            'today' => $now,
            'summary' => $this->summary($user, $folders, $folderIds, $workload, $now, $timezone),
            'workload' => $workload,
            // Every range is computed up front so the CI Completion Trend card can switch between
            // 7 Days / 30 Days / 12 Months instantly on the client, with no second request and no
            // loading state. Each entry is produced by the exact same trend() call the single-range
            // version used, so the calculation and labels per range are unchanged.
            'trends' => $trends,
            'trend' => $trends[$trendRange],
            'trendRange' => $trendRange,
            'trendRanges' => self::TREND_RANGES,
            'activityProgress' => $this->activityProgress($folderIds),
            'workToday' => $this->workToday($user, $folderIds, $now),
            'recentActivity' => $recentActivity['events'],
            'recentActivityHasMore' => $recentActivity['hasMore'],
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
     * Four mutually exclusive buckets over the same folder set, so the donut's slices always add up
     * to the folder total and every percentage is that slice over that same total.
     *
     * Priority is deliberate: a completed folder is never "needs attention", and an overdue folder
     * is surfaced as needing attention rather than being buried in the in-progress count.
     *
     * @param  Collection<int, ClientFolder>  $folders
     * @param  Collection<int, int>  $folderIds
     */
    private function workload(Collection $folders, Collection $folderIds, CarbonImmutable $now): array
    {
        $overdueFolderIds = $this->overdueActivities($folderIds, $now)->distinct()->pluck('client_folder_id')->all();
        $startedFolderIds = CiActivity::query()
            ->whereIn('client_folder_id', $folderIds)
            ->where('status', '!=', ActivityStatus::Pending)
            ->distinct()
            ->pluck('client_folder_id')
            ->all();

        $buckets = ['in_progress' => 0, 'pending' => 0, 'needs_attention' => 0, 'completed' => 0];

        foreach ($folders as $folder) {
            $bucket = match (true) {
                $folder->status === ClientFolderStatus::Completed => 'completed',
                in_array($folder->id, $overdueFolderIds, true) => 'needs_attention',
                in_array($folder->id, $startedFolderIds, true) => 'in_progress',
                default => 'pending',
            };
            $buckets[$bucket]++;
        }

        $total = $folders->count();

        return [
            'total' => $total,
            'segments' => collect([
                ['key' => 'in_progress', 'label' => 'In Progress', 'tone' => 'brand'],
                ['key' => 'pending', 'label' => 'Pending', 'tone' => 'amber'],
                ['key' => 'needs_attention', 'label' => 'Needs Attention', 'tone' => 'danger'],
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
     * drift from the convention the writer used.
     *
     * @param  Collection<int, int>  $folderIds
     */
    private function overdueActivities(Collection $folderIds, CarbonImmutable $now): Builder
    {
        [$startOfToday] = CiActivity::normalizeScheduleInput($now->format('Y-m-d'));

        return CiActivity::query()
            ->whereIn('client_folder_id', $folderIds)
            ->where('status', '!=', ActivityStatus::Completed)
            ->whereNotNull('scheduled_at')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $timed) => $timed->where('scheduled_has_time', true)->where('scheduled_at', '<', $now->utc()))
                ->orWhere(fn (Builder $dateOnly) => $dateOnly->where('scheduled_has_time', false)->where('scheduled_at', '<', $startOfToday)));
    }

    /** The same rule as the query above, applied to one already-loaded activity. */
    private function isOverdue(CiActivity $activity, CarbonImmutable $now): bool
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
     * @param  Collection<int, int>  $folderIds
     */
    private function summary(User $user, Collection $folders, Collection $folderIds, array $workload, CarbonImmutable $now, string $timezone): array
    {
        $completedThisMonth = $folders
            ->filter(fn (ClientFolder $folder): bool => $folder->status === ClientFolderStatus::Completed
                && $folder->completed_at !== null
                && $folder->completed_at->timezone($timezone)->isSameMonth($now))
            ->count();

        $byKey = collect($workload['segments'])->keyBy('key');

        return [
            'assigned' => $folders->count(),
            'in_progress' => $byKey['in_progress']['count'],
            'needs_attention' => $byKey['needs_attention']['count'],
            'completed_this_month' => $completedThisMonth,
            // Report artifacts that finished generating — the same "completed generation" rule the
            // Generated Reports module itself uses, counted once per artifact.
            'reports_ready' => GeneratedReport::query()
                ->whereIn('client_folder_id', $folderIds)
                ->where('status', GenerationStatus::Completed)
                ->count(),
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
     * Each bar is completed-over-applicable, and "applicable" is deliberately narrow: a client with
     * no business income source does not drag the Business Check bar down, and a folder with no
     * CI/BI record yet is not counted as an unfinished CI/BI. A category with no applicable work at
     * all reports 0% and says so in the view rather than inventing a denominator.
     *
     * @param  Collection<int, int>  $folderIds
     */
    private function activityProgress(Collection $folderIds): array
    {
        $folderCount = $folderIds->count();

        $cibiTotal = CibiReport::query()->whereIn('client_folder_id', $folderIds)->count();
        $cibiComplete = CibiReport::query()->whereIn('client_folder_id', $folderIds)->where('state', RecordState::Complete)->count();

        $residenceChecked = ResidenceCheck::query()->whereIn('client_folder_id', $folderIds)->distinct()->count('client_folder_id');

        $businessTotal = IncomeSource::query()->whereIn('client_folder_id', $folderIds)->count();
        $businessChecked = BusinessCheck::query()
            ->whereIn('client_folder_id', $folderIds)
            ->whereNotNull('income_source_id')
            ->distinct()
            ->count('income_source_id');

        $requiredActivities = CiActivity::query()
            ->whereIn('client_folder_id', $folderIds)
            ->whereHas('definition', fn (Builder $query) => $query->where('is_active', true)->where('is_required', true));
        $activityTotal = (clone $requiredActivities)->count();
        $activityComplete = (clone $requiredActivities)->where('status', ActivityStatus::Completed)->count();

        return [
            $this->progressBar('CIBI Investigation', $cibiComplete, $cibiTotal, 'CI/BI records'),
            $this->progressBar('Residence Check', $residenceChecked, $folderCount, 'assigned clients'),
            $this->progressBar('Business Check', $businessChecked, $businessTotal, 'businesses'),
            $this->progressBar('CI Activities (Supporting Proof)', $activityComplete, $activityTotal, 'required activities'),
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
    private function workToday(User $user, Collection $folderIds, CarbonImmutable $now): array
    {
        return CiActivity::query()
            ->whereIn('client_folder_id', $folderIds)
            ->where('creator_id', $user->id)
            ->where('status', '!=', ActivityStatus::Completed)
            ->with(['clientFolder:id,display_name', 'coMaker:id,full_name'])
            ->orderByRaw('CASE WHEN scheduled_at IS NOT NULL AND scheduled_at < ? THEN 0 ELSE 1 END', [$now->utc()])
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [ActivityStatus::FollowUp->value, ActivityStatus::Scheduled->value])
            ->orderBy('updated_at')
            ->limit(self::WORK_LIST_LIMIT)
            ->get()
            ->map(function (CiActivity $activity) use ($now): array {
                $isOverdue = $this->isOverdue($activity, $now);

                return [
                    'id' => $activity->id,
                    'client' => $activity->clientFolder?->display_name ?? 'Unnamed client',
                    'person' => $activity->coMaker?->full_name,
                    'activity' => $activity->name,
                    'status' => $isOverdue ? 'Overdue' : str($activity->status->value)->replace('_', ' ')->title()->toString(),
                    'tone' => $isOverdue ? 'danger' : match ($activity->status) {
                        ActivityStatus::FollowUp => 'amber',
                        ActivityStatus::Scheduled => 'brand',
                        default => 'neutral',
                    },
                    'updated_at' => $activity->updated_at,
                    'url' => route('client-folders.activities.edit', [$activity->client_folder_id, $activity->id]
                        + ($activity->co_maker_id ? ['person' => 'co-maker', 'co_maker_id' => $activity->co_maker_id] : [])),
                    'action' => $activity->status === ActivityStatus::Pending ? 'Open' : 'Continue',
                ];
            })
            ->all();
    }

    /**
     * The existing AuditLog, scoped to the same folders, rendered through the audit vocabulary
     * ClientFolderOverview already owns — this deliberately does not introduce a second history.
     *
     * Reads exactly one row more than the panel shows: that single extra row is what tells the view
     * whether a "View All" is warranted, so the dashboard never loads the whole audit trail (nor
     * runs a second COUNT over it) just to answer "is there a sixth?".
     *
     * @param  Collection<int, int>  $folderIds
     * @return array{events: array<int, array{label: string, icon: string, client: string, user: ?string, at: ?CarbonImmutable}>, hasMore: bool}
     */
    private function recentActivity(Collection $folderIds, string $timezone): array
    {
        $events = AuditLog::query()
            ->whereIn('client_folder_id', $folderIds)
            ->with(['clientFolder:id,display_name', 'user:id,full_name'])
            ->latest('created_at')
            ->latest('id')
            ->limit(self::RECENT_ACTIVITY_LIMIT + 1)
            ->get(['id', 'user_id', 'client_folder_id', 'action', 'created_at']);

        return [
            'events' => $events
                ->take(self::RECENT_ACTIVITY_LIMIT)
                ->map(function (AuditLog $event) use ($timezone): array {
                    $definition = ClientFolderOverview::activityLabel($event->action);

                    return [
                        'label' => $definition['label'],
                        'icon' => $definition['icon'],
                        'client' => $event->clientFolder?->display_name ?? 'Unnamed client',
                        'user' => $event->user?->full_name,
                        'at' => $event->created_at?->timezone($timezone),
                    ];
                })
                ->values()
                ->all(),
            'hasMore' => $events->count() > self::RECENT_ACTIVITY_LIMIT,
        ];
    }
}
