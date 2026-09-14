<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Exceptions\CiActivityAssetTargetConflictException;
use App\Exceptions\DuplicateCiActivityAssetTargetException;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\ClientFolder;
use App\Models\User;
use App\Notifications\CiActivityScheduledReminder;
use App\Services\ClientFolders\CiActivitiesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveCiActivityAssetTarget
{
    public function __construct(
        private readonly CiActivitiesCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
    ) {}

    public function create(User $actor, ClientFolder $folder, CiActivity $activity, array $data): CiActivityAssetTarget
    {
        return DB::transaction(function () use ($actor, $folder, $activity, $data): CiActivityAssetTarget {
            $activity = $this->lockExactParent($folder, $activity);
            $attributes = $this->attributes($actor, $data);

            // Checked AFTER the parent lock is held and against the rows as they exist inside this
            // transaction, never against a snapshot read before it. That ordering is what makes an
            // accidental double-submit — and two CIs pressing Add Assessor at once — warn instead
            // of silently inserting a second row: the later request waits for the lock, re-reads,
            // and only then sees the row the earlier one committed.
            if (! (bool) ($data['allow_duplicate'] ?? false)) {
                $existing = $this->findDuplicate($activity, $attributes);

                if ($existing !== null) {
                    // Thrown before the insert, so there is no target row, no audit, no schedule
                    // notification, no parent status change, no completion evaluation and no
                    // progress recalculation — the transaction rolls back with nothing in it.
                    throw new DuplicateCiActivityAssetTargetException($existing->id);
                }
            }

            $target = $activity->assetTargets()->create($attributes + ['created_by' => $actor->id]);
            $this->audit($actor, $folder, $activity, $target, 'ci_activity.asset_target_created', 'added');

            if ($target->status === ActivityStatus::Scheduled) {
                $this->notifySchedule($activity, $target, CiActivityScheduledReminder::PURPOSE_SCHEDULE_CREATED);
            }

            $this->synchronizeParentWorkflow($actor, $folder, $activity);

            return $target;
        });
    }

    public function update(User $actor, ClientFolder $folder, CiActivity $activity, CiActivityAssetTarget $target, array $data): CiActivityAssetTarget
    {
        return DB::transaction(function () use ($actor, $folder, $activity, $target, $data): CiActivityAssetTarget {
            $activity = $this->lockExactParent($folder, $activity);
            $lockedTarget = $activity->assetTargets()->lockForUpdate()->findOrFail($target->id);

            // Compared against the AUTHORITATIVE locked row, not the route-bound snapshot, and
            // thrown before any field change, schedule change, reminder bookkeeping, audit row,
            // parent synchronization, completion evaluation or progress recalculation — a refused
            // stale save rolls the whole transaction back and leaves nothing behind, not even a
            // notification row (the database channel writes on this same connection).
            if ((int) ($data['expected_revision'] ?? 0) !== $lockedTarget->revision) {
                throw new CiActivityAssetTargetConflictException;
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
            $this->audit($actor, $folder, $activity, $lockedTarget, 'ci_activity.asset_target_updated', 'updated');

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

    public function delete(User $actor, ClientFolder $folder, CiActivity $activity, CiActivityAssetTarget $target): void
    {
        DB::transaction(function () use ($actor, $folder, $activity, $target): void {
            $activity = $this->lockExactParent($folder, $activity);
            $lockedTarget = $activity->assetTargets()->lockForUpdate()->findOrFail($target->id);
            $this->audit($actor, $folder, $activity, $lockedTarget, 'ci_activity.asset_target_deleted', 'deleted');
            $lockedTarget->delete();
            $this->synchronizeParentWorkflow($actor, $folder, $activity);
        });
    }

    public function complete(User $actor, ClientFolder $folder, CiActivity $activity, CiActivityAssetTarget $target): CiActivityAssetTarget
    {
        return DB::transaction(function () use ($actor, $folder, $activity, $target): CiActivityAssetTarget {
            $activity = $this->lockExactParent($folder, $activity);
            $lockedTarget = $activity->assetTargets()->lockForUpdate()->findOrFail($target->id);

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
            $this->audit($actor, $folder, $activity, $lockedTarget, 'ci_activity.asset_target_completed', 'completed');
            $this->synchronizeParentWorkflow($actor, $folder, $activity);

            return $lockedTarget->refresh();
        });
    }

    public function synchronizeParentStatus(CiActivity $activity, User $actor): ActivityStatus
    {
        $status = CiActivityAssetTarget::deriveParentStatus($activity->assetTargets()->pluck('status'));

        $activity->forceFill([
            'status' => $status,
            'scheduled_at' => null,
            'scheduled_has_time' => false,
            'remarks' => null,
            'updated_by' => $actor->id,
            'completed_at' => $status === ActivityStatus::Completed ? ($activity->completed_at ?? now()) : null,
        ])->save();

        return $status;
    }

    /**
     * Duplicate identity is assessor type + normalized office / location, scoped to this ONE parent
     * activity's own rows, so the same office under another person, another Asset Check or another
     * folder is never involved. Status, schedule, remarks and actors are not part of identity, and
     * a different assessor type at the same address is a different target.
     *
     * Compared in PHP rather than in SQL on purpose: the answer must not depend on the database's
     * collation, which differs between the SQLite test connection and MySQL. A parent holds a
     * handful of targets, so reading them is cheap.
     */
    private function findDuplicate(CiActivity $activity, array $attributes): ?CiActivityAssetTarget
    {
        $office = $this->normalizeIdentity($attributes['office_location']);

        return $activity->assetTargets()
            ->where('assessor_type', $attributes['assessor_type'])
            ->get()
            ->first(fn (CiActivityAssetTarget $target): bool => $this->normalizeIdentity($target->office_location) === $office);
    }

    /**
     * Casing, surrounding whitespace and repeated internal spaces are harmless formatting
     * differences, so " City   Hall " and "city hall" are the same office. Nothing fuzzier than
     * that — "City Hall" and "City Hall Annex" stay different offices. Used for COMPARISON only;
     * the stored value keeps the CI's own capitalisation.
     */
    private function normalizeIdentity(?string $value): string
    {
        return Str::lower((string) preg_replace('/\s+/u', ' ', trim((string) $value)));
    }

    private function attributes(User $actor, array $data): array
    {
        $status = ActivityStatus::from($data['status']);
        [$scheduledAt, $scheduledHasTime] = CiActivityAssetTarget::normalizeScheduleInput(
            $status,
            $data['scheduled_at'] ?? null,
            $data['scheduled_time'] ?? null,
        );

        return [
            'assessor_type' => $data['assessor_type'],
            // Tidied for display only: " City   Hall " is stored as "City Hall", never lowercased.
            'office_location' => (string) preg_replace('/\s+/u', ' ', trim((string) $data['office_location'])),
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

    private function notifySchedule(CiActivity $activity, CiActivityAssetTarget $target, string $purpose): void
    {
        $activity->creator?->notify(new CiActivityScheduledReminder(
            $activity,
            $purpose,
            CiActivityScheduledReminder::TARGET_TYPE_ASSET,
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

    private function audit(User $actor, ClientFolder $folder, CiActivity $activity, CiActivityAssetTarget $target, string $action, string $verb): void
    {
        $label = $target->assessorLabel().' — '.$target->office_location;
        AuditLog::create([
            'user_id' => $actor->id,
            'client_folder_id' => $folder->id,
            'action' => $action,
            'module' => 'ci_activities',
            'description' => $actor->full_name.' '.$verb.' '.$label.'.',
            'metadata' => [
                'activity_id' => $activity->id,
                'activity_title' => $activity->name,
                'asset_target_id' => $target->id,
                'asset_target_label' => $label,
                'co_maker_id' => $activity->co_maker_id,
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);
    }
}
