<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\CiActivitiesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Facades\DB;

class UpdateCiActivity
{
    public function __construct(
        private readonly CiActivitiesCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
    ) {}

    public function execute(User $actor, ClientFolder $folder, CiActivity $activity, array $data): void
    {
        DB::transaction(function () use ($actor, $folder, $activity, $data): void {
            $previousStatus = $activity->status;
            $status = ActivityStatus::from($data['status']);
            $activity->update($data + [
                'updated_by' => $actor->id,
                'completed_at' => $status === ActivityStatus::Completed ? ($activity->completed_at ?? now()) : null,
            ]);

            $this->completion->evaluate($folder);
            $this->progress->recalculate($folder);

            $completedNow = $status === ActivityStatus::Completed && $previousStatus !== ActivityStatus::Completed;
            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => $completedNow ? 'ci_activity.completed' : 'ci_activity.updated',
                'module' => 'ci_activities',
                'description' => $completedNow ? 'A CI activity was completed.' : 'A CI activity was updated.',
                'metadata' => [
                    'activity_id' => $activity->id,
                    'activity_definition_id' => $activity->activity_definition_id,
                    'status' => $status->value,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
