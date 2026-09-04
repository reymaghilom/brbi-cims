<?php

namespace App\Notifications;

use App\Models\CiActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class CiActivityScheduledReminder extends Notification
{
    use Queueable;

    public const PURPOSE_SCHEDULE_CREATED = 'schedule_created';

    public const PURPOSE_SCHEDULE_CHANGED = 'schedule_changed';

    public const PURPOSE_DUE_REMINDER = 'due_reminder';

    public const TARGET_TYPE_BANK = 'bank_target';

    public const TARGET_TYPE_ASSET = 'asset_target';

    public function __construct(
        private readonly CiActivity $activity,
        private readonly string $purpose = self::PURPOSE_DUE_REMINDER,
        private readonly ?string $targetType = null,
        private readonly ?int $targetId = null,
        private readonly ?string $targetLabel = null,
        private readonly ?Carbon $targetScheduledAt = null,
        private readonly ?bool $targetScheduledHasTime = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $name = $this->targetLabel ?? $this->activity->name;
        $scheduledAt = $this->targetType !== null ? $this->targetScheduledAt : $this->activity->scheduled_at;
        $scheduledHasTime = $this->targetType !== null ? (bool) $this->targetScheduledHasTime : (bool) $this->activity->scheduled_has_time;
        $scheduleText = $this->scheduleText($scheduledAt, $scheduledHasTime);

        return [
            'type' => 'ci_activity_scheduled_reminder',
            'purpose' => $this->purpose,
            'client_folder_id' => $this->activity->client_folder_id,
            'ci_activity_id' => $this->activity->id,
            'activity' => $this->activity->name,
            'target' => $this->activity->target,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'scheduled_at' => $scheduledAt?->toISOString(),
            'scheduled_has_time' => $scheduledHasTime,
            'title' => $this->title(),
            'message' => match ($this->purpose) {
                self::PURPOSE_SCHEDULE_CREATED => $name.' has been scheduled for '.$scheduleText.'.',
                self::PURPOSE_SCHEDULE_CHANGED => $name.' has been rescheduled to '.$scheduleText.'.',
                default => $name.($scheduledHasTime ? ' is due now.' : ' is due today.'),
            },
            'url' => route('client-folders.activities.index', [
                $this->activity->client_folder_id,
            ] + ($this->activity->co_maker_id ? ['person' => 'co-maker', 'co_maker_id' => $this->activity->co_maker_id] : [])),
        ];
    }

    private function title(): string
    {
        return match ($this->targetType) {
            self::TARGET_TYPE_BANK => match ($this->purpose) {
                self::PURPOSE_SCHEDULE_CREATED => 'Bank / Coop Check Scheduled',
                self::PURPOSE_SCHEDULE_CHANGED => 'Bank / Coop Schedule Updated',
                default => 'Bank / Coop Check Due',
            },
            self::TARGET_TYPE_ASSET => match ($this->purpose) {
                self::PURPOSE_SCHEDULE_CREATED => 'Asset Check Scheduled',
                self::PURPOSE_SCHEDULE_CHANGED => 'Asset Schedule Updated',
                default => 'Asset Check Due',
            },
            default => match ($this->purpose) {
                self::PURPOSE_SCHEDULE_CREATED => 'Activity Scheduled',
                self::PURPOSE_SCHEDULE_CHANGED => 'Schedule Updated',
                default => 'CI Activity Due',
            },
        };
    }

    /**
     * Describes exactly what the user entered — never implies a time was
     * chosen when the schedule is date-only, even though the due-reminder
     * trigger internally still fires at the canonical 8:00 AM in that case.
     */
    private function scheduleText(?Carbon $scheduledAt, bool $scheduledHasTime): ?string
    {
        if ($scheduledAt === null) {
            return null;
        }

        $local = $scheduledAt->timezone(config('cims.display_timezone'));

        return $scheduledHasTime
            ? $local->format('M j, Y').' at '.$local->format('g:i A')
            : $local->format('M j, Y');
    }
}
