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
            $status = ActivityStatus::from($data['status']);
            $activity->update(Arr::except($data, ['expected_updated_at']) + [
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
                    'co_maker_id' => $activity->co_maker_id,
                    'activity_definition_id' => $activity->activity_definition_id,
                    'activity_title' => $activity->definition->name,
                    'status' => $status->value,
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
