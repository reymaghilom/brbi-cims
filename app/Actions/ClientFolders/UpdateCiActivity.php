<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
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
            if (filled($data['expected_updated_at'] ?? null) && ! Carbon::parse($data['expected_updated_at'])->equalTo($activity->updated_at)) {
                throw ValidationException::withMessages([
                    'expected_updated_at' => 'This record has been updated by another CI. Please review the latest version before saving.',
                ]);
            }

            $previousStatus = $activity->status;
            $previousAssignedCiId = $activity->assigned_ci_id;
            $previousSchedule = $activity->scheduled_at?->copy();
            $status = ActivityStatus::from($data['status']);
            $data['scheduled_at'] = in_array($status, [ActivityStatus::Scheduled, ActivityStatus::FollowUp], true)
                ? ($data['scheduled_at'] ?? null)
                : null;
            $reopenedNow = $previousStatus === ActivityStatus::Completed && $status === ActivityStatus::Pending;
            $nextSchedule = filled($data['scheduled_at']) ? Carbon::parse($data['scheduled_at']) : null;
            $scheduleChanged = ($previousSchedule === null) !== ($nextSchedule === null)
                || ($previousSchedule !== null && $nextSchedule !== null && ! $previousSchedule->equalTo($nextSchedule));

            $updates = Arr::except($data, ['expected_updated_at']) + [
                'updated_by' => $actor->id,
                'completed_at' => $status === ActivityStatus::Completed ? ($activity->completed_at ?? now()) : null,
                'reminder_sent_at' => $scheduleChanged || $status !== ActivityStatus::Scheduled
                    ? null
                    : $activity->reminder_sent_at,
            ];
            if ($reopenedNow) {
                $updates['scheduled_at'] = null;
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
