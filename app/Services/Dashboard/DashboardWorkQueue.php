<?php

namespace App\Services\Dashboard;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class DashboardWorkQueue
{
    private const PAGE_SIZE = 5;

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
     * @return array{folders: array<int, array{client: string, url: string, items: array<int, array<string, mixed>>}>, folder_ids: list<int>, folder_count: int, item_count: int}
     */
    public function needsAttention(Collection $folderIds, CarbonImmutable $now, string $timezone): array
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

    /**
     * The one personal section: still-open CI activities THIS user is responsible for, overdue ones
     * first, then the ones already scheduled or being followed up, then the longest untouched. Folder
     * access is collaborative but activity responsibility is not - an activity another investigator
     * created stays on their list, not this one, which is the same creator-based rule the scheduled
     * reminder feed already uses. The folder filter keeps the list inside what the user may access.
     *
     * @param  Collection<int, int>  $folderIds
     */
    public function workToday(User $user, Collection $folderIds, CarbonImmutable $now, int $requestedPage, string $trendRange): LengthAwarePaginator
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
                            $activity, $target, $url, 'bank',
                            $target->institution_name.($target->branch_location ? ' — '.$target->branch_location : ''),
                            $now, $target->id, $target->inquiry_type,
                        ))->all();
                }

                if ($code === ActivityDefinition::ASSET_CHECK_CODE) {
                    $url = route('client-folders.activities.asset-check.show', [$activity->client_folder_id, $activity->id] + $personParams);

                    return $activity->assetTargets->isEmpty()
                        ? [$this->workTodayItem($activity, $activity, $url, 'asset', null, $now)]
                        : $activity->assetTargets->map(fn (CiActivityAssetTarget $target): array => $this->workTodayItem(
                            $activity, $target, $url, 'asset',
                            $target->assessorLabel().' — '.$target->office_location,
                            $now, $target->id,
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

        $lastPage = max(1, (int) ceil($activities->count() / self::PAGE_SIZE));
        $page = min($requestedPage, $lastPage);

        return new LengthAwarePaginator(
            $activities->forPage($page, self::PAGE_SIZE)->values(),
            $activities->count(),
            self::PAGE_SIZE,
            $page,
            [
                'path' => route('home'),
                'query' => ['range' => $trendRange],
                'pageName' => 'work_page',
            ],
        );
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

    /** The same rule as whereOverdue(), applied to one already-loaded activity. */
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
            'completion_expected_revision' => $directCompletion ? $work->revision : null,
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
}
