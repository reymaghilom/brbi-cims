<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitCiActivity
{
    public function execute(User $actor, ClientFolder $folder, CiActivity $activity, array $data): void
    {
        DB::transaction(function () use ($actor, $folder, $activity, $data): void {
            $activity = CiActivity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
            if ($activity->status !== ActivityStatus::Completed) {
                throw ValidationException::withMessages([
                    'submission_activity_id' => 'Only completed activities can be submitted to the Credit Analyst.',
                ])->errorBag('submission');
            }

            $submittedAt = now();
            $activity->update([
                'submitted_at' => $submittedAt,
                'submitted_by' => $actor->id,
                'submitted_to' => $data['submitted_to'] ?? null,
                'submission_note' => $data['submission_note'] ?? null,
                'updated_by' => $actor->id,
            ]);

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'ci_activity.submitted',
                'module' => 'ci_activities',
                'description' => 'A completed CI activity was submitted to the Credit Analyst.',
                'metadata' => [
                    'activity_id' => $activity->id,
                    'co_maker_id' => $activity->co_maker_id,
                    'activity_definition_id' => $activity->activity_definition_id,
                    'activity_title' => $activity->definition->name,
                    'submitted_by' => $actor->id,
                    'submitted_at' => $submittedAt->toISOString(),
                    'submitted_to' => $activity->submitted_to,
                    'submission_note' => $activity->submission_note,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
