<?php

namespace App\Console\Commands;

use App\Enums\ActivityStatus;
use App\Models\CiActivity;
use App\Notifications\CiActivityScheduledReminder;
use Illuminate\Console\Command;

class SendCiActivityReminders extends Command
{
    protected $signature = 'ci-activities:send-reminders';

    protected $description = 'Send due CI activity reminders to each activity creator only';

    public function handle(): int
    {
        $sent = 0;

        CiActivity::query()
            ->where('status', ActivityStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->whereNull('reminder_sent_at')
            ->where('scheduled_at', '<=', now())
            ->whereNotNull('creator_id')
            ->with('creator')
            ->orderBy('id')
            ->eachById(function (CiActivity $activity) use (&$sent): void {
                if ($activity->creator === null) {
                    return;
                }

                $activity->creator->notify(new CiActivityScheduledReminder($activity));
                $activity->forceFill(['reminder_sent_at' => now()])->saveQuietly();
                $sent++;
            });

        $this->info("Sent {$sent} CI activity reminder(s).");

        return self::SUCCESS;
    }
}
