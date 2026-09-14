<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Exceptions\CiActivityConflictException;
use App\Models\ActivityDefinition;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use App\Services\ClientFolders\CiActivitiesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Arr;
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
        // A schedule/reschedule reminder must only ever reach a CI for a change that actually
        // committed: a rolled-back save, and in particular a stale save refused below, must notify
        // nobody. Collected inside the transaction and delivered only after it commits.
        $pendingReminder = null;

        DB::transaction(function () use ($actor, $folder, $activity, $data, &$pendingReminder): void {
            // The route-bound instance is a snapshot from before this transaction, so it is never
            // treated as authoritative: the row is re-read and locked first, scoped to this exact
            // folder and this exact person (co_maker_id NULL = Applicant, an exact id = that
            // Co-Maker) and this exact activity id. Everything below — the status transition, the
            // schedule, the reminder bookkeeping, the audit metadata — then reads from that locked
            // row, and the lock is held through the whole save so the compare and the write are one
            // atomic step. The previous updated_at comparison read the row without a lock, was
            // second-precision, and was skipped entirely when the field was simply omitted.
            $activity = CiActivity::query()
                ->whereKey($activity->getKey())
                ->where('client_folder_id', $folder->id)
                ->when(
                    $activity->co_maker_id === null,
                    fn ($query) => $query->whereNull('co_maker_id'),
                    fn ($query) => $query->where('co_maker_id', $activity->co_maker_id),
                )
                ->lockForUpdate()
                ->firstOrFail();
            $activity->load('definition');

            if (in_array($activity->definition->code, [
                ActivityDefinition::BANK_COOP_CHECK_CODE,
                ActivityDefinition::ASSET_CHECK_CODE,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This parent status is managed through its tracker targets.',
                ]);
            }

            // Thrown before any field change, status transition, reminder bookkeeping, audit row or
            // progress recalculation — a refused stale save leaves nothing behind.
            if ((int) ($data['expected_revision'] ?? 0) !== $activity->revision) {
                throw new CiActivityConflictException;
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

            $updates = Arr::except($data, ['expected_revision', 'scheduled_time']) + [
                'updated_by' => $actor->id,
                // Advanced on every successful update, so the token a form was rendered with can
                // never be replayed. A save that rolls back rolls this back with it.
                'revision' => $activity->revision + 1,
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
                // Held back until this transaction commits — see $pendingReminder above.
                $pendingReminder = [
                    'recipient' => $activity->creator,
                    'activity' => $activity,
                    'purpose' => $rescheduledNow
                        ? CiActivityScheduledReminder::PURPOSE_SCHEDULE_CHANGED
                        : CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED,
                ];
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

        // The transaction has committed, so only a schedule change that actually survived reaches
        // the CI. The recipient stays the activity's creator and both purposes are unchanged.
        if ($pendingReminder !== null) {
            $pendingReminder['recipient']?->notify(new CiActivityScheduledReminder(
                $pendingReminder['activity'],
                $pendingReminder['purpose'],
            ));
        }
    }
}
