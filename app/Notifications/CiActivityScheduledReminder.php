<?php

namespace App\Notifications;

use App\Models\CiActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CiActivityScheduledReminder extends Notification
{
    use Queueable;

    public const PURPOSE_SCHEDULE_CREATED = 'schedule_created';

    public const PURPOSE_SCHEDULE_CHANGED = 'schedule_changed';

    public const PURPOSE_DUE_REMINDER = 'due_reminder';

    public function __construct(
        private readonly CiActivity $activity,
        private readonly string $purpose = self::PURPOSE_DUE_REMINDER,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ci_activity_scheduled_reminder',
            'purpose' => $this->purpose,
            'client_folder_id' => $this->activity->client_folder_id,
            'ci_activity_id' => $this->activity->id,
            'activity' => $this->activity->name,
            'target' => $this->activity->target,
            'scheduled_at' => $this->activity->scheduled_at?->toISOString(),
            'scheduled_has_time' => $this->activity->scheduled_has_time,
            'message' => match ($this->purpose) {
                self::PURPOSE_SCHEDULE_CREATED => $this->activity->name.' was scheduled.',
                self::PURPOSE_SCHEDULE_CHANGED => $this->activity->name.' was rescheduled.',
                default => $this->activity->name.($this->activity->scheduled_has_time ? ' is scheduled now.' : ' is scheduled today.'),
            },
            'url' => route('client-folders.activities.index', [
                $this->activity->client_folder_id,
            ] + ($this->activity->co_maker_id ? ['person' => 'co-maker', 'co_maker_id' => $this->activity->co_maker_id] : [])),
        ];
    }
}
