<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\CiActivityAssetTarget;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\CiActivitiesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Facades\DB;

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
            $target = $activity->assetTargets()->create($this->attributes($actor, $data) + ['created_by' => $actor->id]);
            $this->audit($actor, $folder, $activity, $target, 'ci_activity.asset_target_created', 'added');
            $this->synchronizeParentWorkflow($actor, $folder, $activity);

            return $target;
        });
    }

    public function update(User $actor, ClientFolder $folder, CiActivity $activity, CiActivityAssetTarget $target, array $data): CiActivityAssetTarget
    {
        return DB::transaction(function () use ($actor, $folder, $activity, $target, $data): CiActivityAssetTarget {
            $activity = $this->lockExactParent($folder, $activity);
            $lockedTarget = $activity->assetTargets()->lockForUpdate()->findOrFail($target->id);
            $lockedTarget->update($this->attributes($actor, $data));
            $this->audit($actor, $folder, $activity, $lockedTarget, 'ci_activity.asset_target_updated', 'updated');
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

            $lockedTarget->update([
                'status' => ActivityStatus::Completed,
                'scheduled_at' => null,
                'scheduled_has_time' => false,
                'updated_by' => $actor->id,
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
            'office_location' => $data['office_location'],
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
