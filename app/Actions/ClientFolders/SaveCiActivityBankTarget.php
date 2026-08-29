<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\User;
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
            $target = $activity->bankTargets()->create($this->attributes($actor, $data) + [
                'created_by' => $actor->id,
            ]);
            $this->synchronizeParentWorkflow($actor, $folder, $activity);

            return $target;
        });
    }

    public function update(User $actor, ClientFolder $folder, CiActivity $activity, CiActivityBankTarget $target, array $data): CiActivityBankTarget
    {
        return DB::transaction(function () use ($actor, $folder, $activity, $target, $data): CiActivityBankTarget {
            $activity = $this->lockExactParent($folder, $activity);
            $lockedTarget = $activity->bankTargets()->lockForUpdate()->findOrFail($target->id);
            $lockedTarget->update($this->attributes($actor, $data));
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

            $lockedTarget->update([
                'status' => ActivityStatus::Completed,
                'scheduled_at' => null,
                'scheduled_has_time' => false,
                'updated_by' => $actor->id,
            ]);

            $targetLabel = $lockedTarget->institution_name
                .(filled($lockedTarget->branch_location) ? ' – '.$lockedTarget->branch_location : '');
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

    private function attributes(User $actor, array $data): array
    {
        $status = ActivityStatus::from($data['status']);
        [$scheduledAt, $scheduledHasTime] = CiActivityBankTarget::normalizeScheduleInput(
            $status,
            $data['scheduled_at'] ?? null,
            $data['scheduled_time'] ?? null,
        );

        return [
            'institution_name' => $data['institution_name'],
            'branch_location' => $data['branch_location'] ?? null,
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

    private function lockExactParent(ClientFolder $folder, CiActivity $activity): CiActivity
    {
        abort_unless($activity->client_folder_id === $folder->id, 404);

        return $folder->activities()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
    }
}
