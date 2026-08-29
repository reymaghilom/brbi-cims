<?php

namespace App\Notifications;

use App\Models\CiActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CiActivityScheduledReminder extends Notification
{
    use Queueable;

    public function __construct(private readonly CiActivity $activity) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ci_activity_scheduled_reminder',
            'client_folder_id' => $this->activity->client_folder_id,
            'ci_activity_id' => $this->activity->id,
            'activity' => $this->activity->name,
            'target' => $this->activity->target,
            'scheduled_at' => $this->activity->scheduled_at?->toISOString(),
            'message' => $this->activity->name.' is scheduled now.',
            'url' => route('client-folders.activities.edit', [
                $this->activity->client_folder_id,
                $this->activity->id,
            ] + ($this->activity->co_maker_id ? ['person' => 'co-maker', 'co_maker_id' => $this->activity->co_maker_id] : [])),
        ];
    }
}
