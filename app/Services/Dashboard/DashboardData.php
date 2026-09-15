<?php

namespace App\Services\Dashboard;

use App\Enums\ClientFolderStatus;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\CiActivityHistoryFeed;
use App\Services\ClientFolders\ClientFolderOverview;
use App\Services\Reports\ReportWorkItem;
use App\Services\Reports\ReportWorkspaceQuery;
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
    public const TREND_RANGES = DashboardTrendData::RANGES;

    public const DEFAULT_TREND_RANGE = DashboardTrendData::DEFAULT_RANGE;

    private const RECENT_ACTIVITY_LIMIT = 3;

    public function __construct(
        private readonly ReportWorkspaceQuery $reports,
        private readonly DashboardTrendData $trendData,
        private readonly DashboardProgressData $progressData,
        private readonly DashboardWorkQueue $workQueue,
    ) {}

    public function for(User $user, ?string $trendRange = null, mixed $workPage = null): array
    {
        $trendRange = $this->trendData->normalizeRange($trendRange);
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

        $mandatory = $this->progressData->mandatory($folderIds);
        $readyReports = $this->reports->completedItems($user);
        $needsAttention = $this->workQueue->needsAttention($folderIds, $now, $timezone);
        $workload = $this->progressData->workload($folders, $mandatory);
        $recentActivity = $this->recentActivity($folderIds, $timezone);
        $trends = $this->trendData->all($user, $now, $timezone);

        return [
            'today' => $now,
            'summary' => $this->summary($folders, $mandatory, $readyReports, $needsAttention, $now, $timezone),
            'workload' => $workload,
            'needsAttention' => $needsAttention['folders'],
            'kpiDetails' => $this->kpiDetails($folders, $mandatory, $readyReports, $now, $timezone),
            // Every range is computed up front so the CI Completion Trend card can switch between
            // 7 Days / 30 Days / 12 Months instantly on the client, with no second request and no
            // loading state. Each entry is produced by the exact same DashboardTrendData::for() call the
            // single-range version used, so the calculation and labels per range are unchanged.
            'trends' => $trends,
            'trend' => $trends[$trendRange],
            'trendRange' => $trendRange,
            'trendRanges' => self::TREND_RANGES,
            'activityProgress' => $this->progressData->activity($folderIds),
            'workToday' => $this->workQueue->workToday($user, $folderIds, $now, max(1, (int) $workPage), $trendRange),
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
            'in_progress' => collect($mandatory)->filter(fn (array $folder): bool => $this->progressData->isInProgress($folder))->count(),
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
            'in_progress' => $folders->filter(fn (ClientFolder $folder): bool => $this->progressData->isInProgress($mandatory[$folder->id] ?? null))
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
