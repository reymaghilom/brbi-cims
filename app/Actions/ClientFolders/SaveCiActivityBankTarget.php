<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Exceptions\CiActivityBankTargetConflictException;
use App\Exceptions\DuplicateCiActivityBankTargetException;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use App\Services\ClientFolders\CiActivitiesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Facades\DB;

class SaveCiActivityBankTarget
{
    public function __construct(
        private readonly CiActivitiesCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
    ) {}

    public function create(User $actor, ClientFolder $folder, CiActivity $activity, array $data): CiActivityBankTarget
    {
        return DB::transaction(function () use ($actor, $folder, $activity, $data): CiActivityBankTarget {
            $activity = $this->lockExactParent($folder, $activity);
            $attributes = $this->attributes($actor, $data);

            // Checked AFTER the parent lock is held and against the rows as they exist inside this
            // transaction, never against a snapshot read before it. That ordering is what makes an
            // accidental double-submit — and two CIs pressing Add at once — warn instead of
            // silently inserting a second row: the later request waits for the lock, re-reads, and
            // only then sees the row the earlier one committed.
            if (! (bool) ($data['allow_duplicate'] ?? false)) {
                $existing = $this->findDuplicate($activity, $attributes);

                if ($existing !== null) {
                    // Thrown before the insert, so there is no target row, no parent status change,
                    // no completion evaluation, no progress recalculation, no audit and no schedule
                    // notification — the transaction rolls back with nothing in it.
                    throw new DuplicateCiActivityBankTargetException($existing->id, $existing->targetLabel());
                }
            }

            $target = $activity->bankTargets()->create($attributes + [
                'created_by' => $actor->id,
            ]);

            if ($target->status === ActivityStatus::Scheduled) {
                $this->notifySchedule($activity, $target, CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED);
            }

            $this->synchronizeParentWorkflow($actor, $folder, $activity);

            return $target;
        });
    }

    public function update(User $actor, ClientFolder $folder, CiActivity $activity, CiActivityBankTarget $target, array $data): CiActivityBankTarget
    {
        return DB::transaction(function () use ($actor, $folder, $activity, $target, $data): CiActivityBankTarget {
            $activity = $this->lockExactParent($folder, $activity);
            $lockedTarget = $activity->bankTargets()->lockForUpdate()->findOrFail($target->id);

            // Compared against the AUTHORITATIVE locked row, not the route-bound snapshot, and
            // thrown before any field change, schedule change, reminder bookkeeping, parent
            // synchronization, completion evaluation or progress recalculation — a refused stale
            // save rolls the whole transaction back and leaves nothing behind, not even a
            // notification row (the database channel writes on this same connection).
            if ((int) ($data['expected_revision'] ?? 0) !== $lockedTarget->revision) {
                throw new CiActivityBankTargetConflictException;
            }

            $previousStatus = $lockedTarget->status;
            $previousScheduledAt = $lockedTarget->scheduled_at?->copy();
            $previousScheduledHasTime = $lockedTarget->scheduled_has_time;

            $attributes = $this->attributes($actor, $data);
            $scheduleChanged = ($previousScheduledAt === null) !== ($attributes['scheduled_at'] === null)
                || ($previousScheduledAt !== null && $attributes['scheduled_at'] !== null && ! $previousScheduledAt->equalTo($attributes['scheduled_at']))
                || $previousScheduledHasTime !== $attributes['scheduled_has_time'];
            $attributes['reminder_sent_at'] = $scheduleChanged || $attributes['status'] !== ActivityStatus::Scheduled
                ? null
                : $lockedTarget->reminder_sent_at;

            // Advanced on every successful update, so the token a form was rendered with can never
            // be replayed. A save that rolls back rolls this back with it.
            $attributes['revision'] = $lockedTarget->revision + 1;

            $lockedTarget->update($attributes);

            $scheduledNow = $lockedTarget->status === ActivityStatus::Scheduled && $previousStatus !== ActivityStatus::Scheduled;
            $rescheduledNow = $lockedTarget->status === ActivityStatus::Scheduled && $previousStatus === ActivityStatus::Scheduled && $scheduleChanged;
            if ($scheduledNow) {
                $this->notifySchedule($activity, $lockedTarget, CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED);
            } elseif ($rescheduledNow) {
                $this->notifySchedule($activity, $lockedTarget, CiActivityScheduledReminder::PURPOSE_SCHEDULE_CHANGED);
            }

            $this->synchronizeParentWorkflow($actor, $folder, $activity);

            return $lockedTarget->refresh();
        });
    }

    public function delete(User $actor, ClientFolder $folder, CiActivity $activity, CiActivityBankTarget $target): void
    {
        DB::transaction(function () use ($actor, $folder, $activity, $target): void {
            $activity = $this->lockExactParent($folder, $activity);
            $activity->bankTargets()->lockForUpdate()->findOrFail($target->id)->delete();
            $this->synchronizeParentWorkflow($actor, $folder, $activity);
        });
    }

    public function complete(User $actor, ClientFolder $folder, CiActivity $activity, CiActivityBankTarget $target): CiActivityBankTarget
    {
        return DB::transaction(function () use ($actor, $folder, $activity, $target): CiActivityBankTarget {
            $activity = $this->lockExactParent($folder, $activity);
            $lockedTarget = $activity->bankTargets()->lockForUpdate()->findOrFail($target->id);

            if ($lockedTarget->status === ActivityStatus::Completed) {
                return $lockedTarget;
            }

            // Completion mutates the target, so it advances the token too — an edit form opened
            // before the completion must fail as stale rather than silently overwrite the
            // Completed state. The already-Completed short-circuit above returns first, so a
            // repeated completion neither advances the revision again nor writes a second audit.
            $lockedTarget->update([
                'status' => ActivityStatus::Completed,
                'scheduled_at' => null,
                'scheduled_has_time' => false,
                'reminder_sent_at' => null,
                'updated_by' => $actor->id,
                'revision' => $lockedTarget->revision + 1,
            ]);

            $targetLabel = $lockedTarget->targetLabel();
            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'ci_activity.bank_target_completed',
                'module' => 'ci_activities',
                'description' => $actor->full_name.' completed '.$targetLabel.'.',
                'metadata' => [
                    'activity_id' => $activity->id,
                    'activity_title' => $activity->name,
                    'bank_target_id' => $lockedTarget->id,
                    'bank_target_label' => $targetLabel,
                    'bank_target_type' => $lockedTarget->inquiry_type,
                    'bank_target_type_label' => $lockedTarget->inquiryTypeLabel(),
                    'co_maker_id' => $activity->co_maker_id,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $this->synchronizeParentWorkflow($actor, $folder, $activity);

            return $lockedTarget->refresh();
        });
    }

    public function synchronizeParentStatus(CiActivity $activity, User $actor): ActivityStatus
    {
        $status = CiActivityBankTarget::deriveParentStatus($activity->bankTargets()->pluck('status'));

        $activity->forceFill([
            'status' => $status,
            'scheduled_at' => null,
            'scheduled_has_time' => false,
            'remarks' => null,
            'updated_by' => $actor->id,
            'completed_at' => $status === ActivityStatus::Completed
                ? ($activity->completed_at ?? now())
                : null,
        ])->save();

        return $status;
    }

    /**
     * Duplicate identity is inquiry type + normalized institution + normalized branch, scoped to
     * this ONE parent activity's own rows, so the same bank under another person, another parent
     * activity or another folder is never involved. Branch is already NULL for a Loan Inquiry, so
     * that type compares on institution alone. Status, schedule, remarks and actors are not part
     * of identity.
     *
     * Compared in PHP rather than in SQL on purpose: the answer must not depend on the database's
     * collation, which differs between the SQLite test connection and MySQL. A parent holds a
     * handful of targets, so reading them is cheap.
     */
    private function findDuplicate(CiActivity $activity, array $attributes): ?CiActivityBankTarget
    {
        $institution = CiActivityBankTarget::normalizeIdentity($attributes['institution_name']);
        $branch = CiActivityBankTarget::normalizeIdentity($attributes['branch_location']);

        return $activity->bankTargets()
            ->where('inquiry_type', $attributes['inquiry_type'])
            ->get()
            ->first(fn (CiActivityBankTarget $target): bool => CiActivityBankTarget::normalizeIdentity($target->institution_name) === $institution
                && CiActivityBankTarget::normalizeIdentity($target->branch_location) === $branch);
    }

    private function attributes(User $actor, array $data): array
    {
        $status = ActivityStatus::from($data['status']);
        $inquiryType = $data['inquiry_type'];
        [$scheduledAt, $scheduledHasTime] = CiActivityBankTarget::normalizeScheduleInput(
            $status,
            $data['scheduled_at'] ?? null,
            $data['scheduled_time'] ?? null,
        );

        return [
            'inquiry_type' => $inquiryType,
            'institution_name' => $data['institution_name'],
            'branch_location' => $inquiryType === CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY
                ? null
                : ($data['branch_location'] ?? null),
            'status' => $status,
            'scheduled_at' => $scheduledAt,
            'scheduled_has_time' => $scheduledHasTime,
            'remarks' => $data['remarks'] ?? null,
            'updated_by' => $actor->id,
        ];
    }

    private function synchronizeParentWorkflow(User $actor, ClientFolder $folder, CiActivity $activity): void
    {
        $this->synchronizeParentStatus($activity, $actor);
        $this->completion->evaluate($folder);
        $this->progress->recalculate($folder);
    }

    private function notifySchedule(CiActivity $activity, CiActivityBankTarget $target, string $purpose): void
    {
        $activity->creator?->notify(new CiActivityScheduledReminder(
            $activity,
            $purpose,
            CiActivityScheduledReminder::TARGET_TYPE_BANK,
            $target->id,
            $target->targetLabel(),
            $target->scheduled_at,
            $target->scheduled_has_time,
        ));
    }

    private function lockExactParent(ClientFolder $folder, CiActivity $activity): CiActivity
    {
        abort_unless($activity->client_folder_id === $folder->id, 404);

        return $folder->activities()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
    }
}
