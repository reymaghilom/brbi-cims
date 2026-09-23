<?php

namespace App\Services\Notifications;

use App\Enums\ActivityStatus;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ProcessDueCiActivityReminders
{
    public function process(?User $user = null): int
    {
        return $this->processParents($user)
            + $this->processBankTargets($user)
            + $this->processAssetTargets($user);
    }

    private function processParents(?User $user): int
    {
        $sent = 0;
        $query = $this->due(CiActivity::query());

        if ($user !== null) {
            $query->where('creator_id', $user->id);
        }

        $query->whereNotNull('creator_id')->eachById(function (CiActivity $candidate) use ($user, &$sent): void {
            DB::transaction(function () use ($candidate, $user, &$sent): void {
                $activity = CiActivity::query()->with('creator')->lockForUpdate()->find($candidate->id);

                if (! $this->isDue($activity) || $activity->creator === null || ($user !== null && $activity->creator_id !== $user->id)) {
                    return;
                }

                $activity->creator->notify(new CiActivityScheduledReminder($activity));
                $activity->forceFill(['reminder_sent_at' => now()])->saveQuietly();
                $sent++;
            });
        });

        return $sent;
    }

    private function processBankTargets(?User $user): int
    {
        $query = $this->due(CiActivityBankTarget::query());

        if ($user !== null) {
            $query->whereHas('activity', fn (Builder $activities) => $activities->where('creator_id', $user->id));
        }

        return $this->processTargets($query, CiActivityBankTarget::class, CiActivityScheduledReminder::TARGET_TYPE_BANK, $user);
    }

    private function processAssetTargets(?User $user): int
    {
        $query = $this->due(CiActivityAssetTarget::query());

        if ($user !== null) {
            $query->whereHas('activity', fn (Builder $activities) => $activities->where('creator_id', $user->id));
        }

        return $this->processTargets($query, CiActivityAssetTarget::class, CiActivityScheduledReminder::TARGET_TYPE_ASSET, $user);
    }

    /**
     * @param  class-string<CiActivityBankTarget|CiActivityAssetTarget>  $modelClass
     */
    private function processTargets(Builder $query, string $modelClass, string $targetType, ?User $user): int
    {
        $sent = 0;

        $query->eachById(function (Model $candidate) use ($modelClass, $targetType, $user, &$sent): void {
            DB::transaction(function () use ($candidate, $modelClass, $targetType, $user, &$sent): void {
                /** @var CiActivityBankTarget|CiActivityAssetTarget|null $target */
                $target = $modelClass::query()->with('activity.creator')->lockForUpdate()->find($candidate->getKey());
                $activity = $target?->activity;

                if (! $this->isDue($target) || $activity === null || $activity->creator === null || ($user !== null && $activity->creator_id !== $user->id)) {
                    return;
                }

                $activity->creator->notify(new CiActivityScheduledReminder(
                    $activity,
                    CiActivityScheduledReminder::PURPOSE_DUE_REMINDER,
                    $targetType,
                    $target->id,
                    $target->targetLabel(),
                    $target->scheduled_at,
                    $target->scheduled_has_time,
                ));
                $target->forceFill(['reminder_sent_at' => now()])->saveQuietly();
                $sent++;
            });
        });

        return $sent;
    }

    private function due(Builder $query): Builder
    {
        return $query
            ->where('status', ActivityStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->whereNull('reminder_sent_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('id');
    }

    private function isDue(?Model $record): bool
    {
        return $record !== null
            && $record->status === ActivityStatus::Scheduled
            && $record->scheduled_at !== null
            && $record->scheduled_at->lessThanOrEqualTo(now())
            && $record->reminder_sent_at === null;
    }
}
