<?php

namespace App\Console\Commands;

use App\Enums\ActivityStatus;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\CiActivityBankTarget;
use App\Notifications\CiActivityScheduledReminder;
use Illuminate\Console\Command;

class SendCiActivityReminders extends Command
{
    protected $signature = 'ci-activities:send-reminders';

    protected $description = 'Send due CI activity reminders — parent activities and each individual Bank/Asset target — to each activity creator only';

    public function handle(): int
    {
        $sent = $this->sendParentReminders()
            + $this->sendBankTargetReminders()
            + $this->sendAssetTargetReminders();

        $this->info("Sent {$sent} CI activity reminder(s).");

        return self::SUCCESS;
    }

    private function sendParentReminders(): int
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

        return $sent;
    }

    private function sendBankTargetReminders(): int
    {
        $sent = 0;

        CiActivityBankTarget::query()
            ->where('status', ActivityStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->whereNull('reminder_sent_at')
            ->where('scheduled_at', '<=', now())
            ->with('activity.creator')
            ->orderBy('id')
            ->eachById(function (CiActivityBankTarget $target) use (&$sent): void {
                $activity = $target->activity;
                if ($activity === null || $activity->creator === null) {
                    return;
                }

                $activity->creator->notify(new CiActivityScheduledReminder(
                    $activity,
                    CiActivityScheduledReminder::PURPOSE_DUE_REMINDER,
                    CiActivityScheduledReminder::TARGET_TYPE_BANK,
                    $target->id,
                    $target->targetLabel(),
                    $target->scheduled_at,
                    $target->scheduled_has_time,
                ));
                $target->forceFill(['reminder_sent_at' => now()])->saveQuietly();
                $sent++;
            });

        return $sent;
    }

    private function sendAssetTargetReminders(): int
    {
        $sent = 0;

        CiActivityAssetTarget::query()
            ->where('status', ActivityStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->whereNull('reminder_sent_at')
            ->where('scheduled_at', '<=', now())
            ->with('activity.creator')
            ->orderBy('id')
            ->eachById(function (CiActivityAssetTarget $target) use (&$sent): void {
                $activity = $target->activity;
                if ($activity === null || $activity->creator === null) {
                    return;
                }

                $activity->creator->notify(new CiActivityScheduledReminder(
                    $activity,
                    CiActivityScheduledReminder::PURPOSE_DUE_REMINDER,
                    CiActivityScheduledReminder::TARGET_TYPE_ASSET,
                    $target->id,
                    $target->targetLabel(),
                    $target->scheduled_at,
                    $target->scheduled_has_time,
                ));
                $target->forceFill(['reminder_sent_at' => now()])->saveQuietly();
                $sent++;
            });

        return $sent;
    }
}
