<?php

namespace App\Services\Notifications;

use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the header bell's "Scheduled Today" feed from the actual persisted
 * CiActivityScheduledReminder notifications, resolving each one to its exact
 * subject — the parent CiActivity itself, or one specific Bank/Asset target —
 * instead of assuming every notification maps to the parent's own
 * scheduled_at (which is legitimately NULL for Bank/Asset target reminders).
 *
 * Every schedule comparison here is null-safe by construction: a subject is
 * only ever admitted into $items when it has a real, non-null scheduled_at,
 * and a notification is only matched against that item's own live value.
 */
class ScheduledTodayNotificationFeed
{
    public readonly Collection $items;

    public readonly Collection $notificationsByKey;

    public readonly int $unreadCount;

    public static function build(User $user): self
    {
        return new self($user);
    }

    private function __construct(User $user)
    {
        $this->items = $this->buildItems($user);
        $this->notificationsByKey = $this->matchNotificationsToItems($user, $this->items);
        $this->unreadCount = $this->notificationsByKey
            ->filter(fn (DatabaseNotification $notification): bool => $notification->read_at === null)
            ->count();
    }

    /** @return Collection<int, object> */
    private function buildItems(User $user): Collection
    {
        $parents = CiActivity::query()
            ->scheduledTodayForCreator($user)
            ->with(['clientFolder:id,display_name', 'coMaker:id,client_folder_id,full_name'])
            ->get()
            ->map(fn (CiActivity $activity): object => $this->itemFromParent($activity));

        $bankTargets = CiActivityBankTarget::query()
            ->scheduledTodayForCreator($user)
            ->with(['activity.clientFolder:id,display_name', 'activity.coMaker:id,client_folder_id,full_name'])
            ->get()
            ->map(fn (CiActivityBankTarget $target): ?object => $this->itemFromTarget(
                $target->activity,
                CiActivityScheduledReminder::TARGET_TYPE_BANK,
                $target->id,
                $target->targetLabel(),
                $target->scheduled_at,
                $target->scheduled_has_time,
            ))
            ->filter();

        $assetTargets = CiActivityAssetTarget::query()
            ->scheduledTodayForCreator($user)
            ->with(['activity.clientFolder:id,display_name', 'activity.coMaker:id,client_folder_id,full_name'])
            ->get()
            ->map(fn (CiActivityAssetTarget $target): ?object => $this->itemFromTarget(
                $target->activity,
                CiActivityScheduledReminder::TARGET_TYPE_ASSET,
                $target->id,
                $target->targetLabel(),
                $target->scheduled_at,
                $target->scheduled_has_time,
            ))
            ->filter();

        return $parents->concat($bankTargets)->concat($assetTargets)
            ->sortBy(fn (object $item): int => $item->scheduled_at->timestamp)
            ->values();
    }

    private function itemFromParent(CiActivity $activity): object
    {
        return (object) [
            'key' => 'activity:'.$activity->id,
            'ci_activity_id' => $activity->id,
            'target_type' => null,
            'target_id' => null,
            'name' => $activity->name,
            'client_folder_id' => $activity->client_folder_id,
            'client_folder_display_name' => $activity->clientFolder?->display_name ?? 'Client Folder',
            'co_maker_id' => $activity->co_maker_id,
            'person_label' => $activity->coMaker ? 'Co-Maker: '.$activity->coMaker->full_name : 'Applicant',
            'scheduled_at' => $activity->scheduled_at,
            'scheduled_has_time' => (bool) $activity->scheduled_has_time,
            'url' => route('client-folders.activities.index', [
                $activity->client_folder_id,
            ] + ($activity->co_maker_id ? ['person' => 'co-maker', 'co_maker_id' => $activity->co_maker_id] : []) + ['status' => 'scheduled_today']),
        ];
    }

    private function itemFromTarget(?CiActivity $activity, string $targetType, int $targetId, string $label, ?Carbon $scheduledAt, bool $scheduledHasTime): ?object
    {
        // Defensive: a target reminder is only ever meaningful alongside its exact parent.
        if ($activity === null || $scheduledAt === null) {
            return null;
        }

        return (object) [
            'key' => $targetType.':'.$targetId,
            'ci_activity_id' => $activity->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'name' => $label,
            'client_folder_id' => $activity->client_folder_id,
            'client_folder_display_name' => $activity->clientFolder?->display_name ?? 'Client Folder',
            'co_maker_id' => $activity->co_maker_id,
            'person_label' => $activity->coMaker ? 'Co-Maker: '.$activity->coMaker->full_name : 'Applicant',
            'scheduled_at' => $scheduledAt,
            'scheduled_has_time' => $scheduledHasTime,
            'url' => route('client-folders.activities.index', [
                $activity->client_folder_id,
            ] + ($activity->co_maker_id ? ['person' => 'co-maker', 'co_maker_id' => $activity->co_maker_id] : []) + ['status' => 'scheduled_today']),
        ];
    }

    /**
     * For each today item, find the single notification (read or unread,
     * newest wins) whose own stored schedule still matches that item's
     * current live schedule — so a stale notification from a since-changed
     * reschedule is never shown as if it were still current.
     *
     * @param  Collection<int, object>  $items
     * @return Collection<string, DatabaseNotification> keyed by the same item key
     */
    private function matchNotificationsToItems(User $user, Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return collect();
        }

        $activityIds = $items->pluck('ci_activity_id')->unique()->values()->all();

        $notifications = $user->notifications()
            ->where('type', CiActivityScheduledReminder::class)
            ->whereIn('data->ci_activity_id', $activityIds)
            ->latest()
            ->get();

        $itemsByKey = $items->keyBy('key');

        return $notifications
            ->map(function (DatabaseNotification $notification) use ($itemsByKey): ?array {
                $key = self::keyFromPayload($notification->data);
                $item = $key !== null ? $itemsByKey->get($key) : null;
                if ($item === null) {
                    return null;
                }

                $notifiedSchedule = data_get($notification->data, 'scheduled_at');
                if (blank($notifiedSchedule)) {
                    return null;
                }

                try {
                    $notifiedAt = Carbon::parse($notifiedSchedule);
                } catch (\Throwable) {
                    return null;
                }

                if (! $notifiedAt->equalTo($item->scheduled_at)) {
                    return null;
                }

                return [$key, $notification];
            })
            ->filter()
            ->unique(fn (array $pair): string => $pair[0])
            ->mapWithKeys(fn (array $pair): array => [$pair[0] => $pair[1]]);
    }

    /**
     * Resolves the exact subject a notification payload refers to. Returns
     * null for any malformed/historical payload instead of guessing — a
     * missing or unrecognized key simply means "no current match", never a
     * crash.
     */
    public static function keyFromPayload(mixed $data): ?string
    {
        $targetType = data_get($data, 'target_type');
        $targetId = data_get($data, 'target_id');
        if (is_string($targetType) && in_array($targetType, [CiActivityScheduledReminder::TARGET_TYPE_BANK, CiActivityScheduledReminder::TARGET_TYPE_ASSET], true) && is_numeric($targetId)) {
            return $targetType.':'.(int) $targetId;
        }

        $activityId = data_get($data, 'ci_activity_id');

        return is_numeric($activityId) ? 'activity:'.(int) $activityId : null;
    }
}
