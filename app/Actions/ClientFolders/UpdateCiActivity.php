<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use App\Services\ClientFolders\CiActivitiesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCiActivity
{
    public function __construct(
        private readonly CiActivitiesCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
    ) {}

    public function execute(User $actor, ClientFolder $folder, CiActivity $activity, array $data): void
    {
        DB::transaction(function () use ($actor, $folder, $activity, $data): void {
            if (in_array($activity->definition->code, [
                ActivityDefinition::BANK_COOP_CHECK_CODE,
                ActivityDefinition::ASSET_CHECK_CODE,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This parent status is managed through its tracker targets.',
                ]);
            }

            if (filled($data['expected_updated_at'] ?? null) && ! Carbon::parse($data['expected_updated_at'])->equalTo($activity->updated_at)) {
                throw ValidationException::withMessages([
                    'expected_updated_at' => 'This record has been updated by another CI. Please review the latest version before saving.',
                ]);
            }

            $previousStatus = $activity->status;
            $previousAssignedCiId = $activity->assigned_ci_id;
            $previousSchedule = $activity->scheduled_at?->copy();
            $previousScheduleHasTime = $activity->scheduled_has_time;
            $status = ActivityStatus::from($data['status']);
            $isDefaultCheck = in_array($activity->definition->code, [
                ActivityDefinition::BARANGAY_CHECK_CODE,
                ActivityDefinition::NEIGHBOR_CHECK_CODE,
            ], true);
            [$nextSchedule, $nextScheduleHasTime] = in_array($status, [ActivityStatus::Scheduled, ActivityStatus::FollowUp], true)
                ? CiActivity::normalizeScheduleInput($data['scheduled_at'] ?? null, $data['scheduled_time'] ?? null)
                : [null, ! $isDefaultCheck];
            if ($isDefaultCheck && $nextSchedule === null) {
                $nextScheduleHasTime = false;
            }
            $data['scheduled_at'] = $nextSchedule;
            $data['scheduled_has_time'] = $nextScheduleHasTime;
            $reopenedNow = $previousStatus === ActivityStatus::Completed && $status === ActivityStatus::Pending;
            $scheduleChanged = ($previousSchedule === null) !== ($nextSchedule === null)
                || ($previousSchedule !== null && $nextSchedule !== null && ! $previousSchedule->equalTo($nextSchedule))
                || $previousScheduleHasTime !== $nextScheduleHasTime;

            $updates = Arr::except($data, ['expected_updated_at', 'scheduled_time']) + [
                'updated_by' => $actor->id,
                'completed_at' => $status === ActivityStatus::Completed ? ($activity->completed_at ?? now()) : null,
                'reminder_sent_at' => $scheduleChanged || $status !== ActivityStatus::Scheduled
                    ? null
                    : $activity->reminder_sent_at,
            ];
            if ($reopenedNow) {
                $updates['scheduled_at'] = null;
                $updates['scheduled_has_time'] = ! $isDefaultCheck;
                $updates['reminder_sent_at'] = null;
            }
            $activity->update($updates);

            $this->completion->evaluate($folder);
            $this->progress->recalculate($folder);

            $completedNow = $status === ActivityStatus::Completed && $previousStatus !== ActivityStatus::Completed;
            $scheduledNow = $status === ActivityStatus::Scheduled && $previousStatus !== ActivityStatus::Scheduled;
            $rescheduledNow = $status === ActivityStatus::Scheduled && $previousStatus === ActivityStatus::Scheduled && $scheduleChanged;
            $action = match (true) {
                $reopenedNow => 'ci_activity.reopened',
                $completedNow => 'ci_activity.completed',
                $rescheduledNow => 'ci_activity.rescheduled',
                $scheduledNow => 'ci_activity.scheduled',
                default => 'ci_activity.updated',
            };

            if ($scheduledNow || $rescheduledNow) {
                $activity->creator?->notify(new CiActivityScheduledReminder(
                    $activity,
                    $rescheduledNow
                        ? CiActivityScheduledReminder::PURPOSE_SCHEDULE_CHANGED
                        : CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED,
                ));
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => $action,
                'module' => 'ci_activities',
                'description' => match ($action) {
                    'ci_activity.reopened' => 'A completed CI activity was reopened.',
                    'ci_activity.completed' => 'A CI activity was completed.',
                    'ci_activity.scheduled' => 'A CI activity was scheduled.',
                    'ci_activity.rescheduled' => 'A CI activity was rescheduled.',
                    default => 'A CI activity was updated.',
                },
                'metadata' => [
                    'activity_id' => $activity->id,
                    'co_maker_id' => $activity->co_maker_id,
                    'activity_definition_id' => $activity->activity_definition_id,
                    'activity_title' => $activity->definition->name,
                    'status' => $status->value,
                    'scheduled_at' => $activity->scheduled_at?->toISOString(),
                    'scheduled_has_time' => $activity->scheduled_has_time,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            if ($activity->assigned_ci_id !== $previousAssignedCiId) {
                AuditLog::create([
                    'user_id' => $actor->id,
                    'client_folder_id' => $folder->id,
                    'action' => 'ci_activity.assignment_changed',
                    'module' => 'ci_activities',
                    'description' => 'The assigned Credit Investigator for a CI activity was changed.',
                    'metadata' => [
                        'activity_id' => $activity->id,
                        'co_maker_id' => $activity->co_maker_id,
                        'activity_title' => $activity->definition->name,
                        'previous_assigned_ci_id' => $previousAssignedCiId,
                        'assigned_ci_id' => $activity->assigned_ci_id,
                    ],
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);
            }
        });
    }
}
