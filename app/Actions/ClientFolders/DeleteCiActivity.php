<?php

namespace App\Actions\ClientFolders;

use App\Actions\Media\RemoveCiActivityProof;
use App\Models\AuditLog;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\CiActivitiesCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Facades\DB;

class DeleteCiActivity
{
    public function __construct(
        private readonly CiActivitiesCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
        private readonly RemoveCiActivityProof $proofRemoval,
    ) {}

    public function execute(User $actor, ClientFolder $folder, CiActivity $activity): void
    {
        $this->executeMany($actor, $folder, [$activity]);
    }

    /** @param iterable<CiActivity> $activities */
    public function executeMany(User $actor, ClientFolder $folder, iterable $activities): void
    {
        // Barangay Check and Neighbor Check used to be undeletable, because nothing but the
        // retired auto-seeding could put them back. They are manually added built-in types now, so
        // deleting one is an ordinary, reversible action: the requirement itself stays mandatory
        // and simply reads incomplete again until the type is re-added and completed. Progress and
        // folder status are recalculated authoritatively below, exactly as for any other activity.
        $activities = collect($activities)->values();

        $cleanups = DB::transaction(function () use ($actor, $folder, $activities): array {
            $cleanups = [];

            foreach ($activities as $activity) {
                array_push($cleanups, ...$this->proofRemoval->detachForActivityDeletion($folder, $activity));
                $this->deleteOne($actor, $folder, $activity);
            }

            $this->completion->evaluate($folder);
            $this->progress->recalculate($folder);

            return $cleanups;
        });

        foreach ($cleanups as $cleanup) {
            $this->proofRemoval->retireStorage($cleanup);
        }
    }

    private function deleteOne(User $actor, ClientFolder $folder, CiActivity $activity): void
    {
        $activity->forceFill(['updated_by' => $actor->id])->save();
        $deletionTime = now();

        AuditLog::create([
            'user_id' => $actor->id,
            'client_folder_id' => $folder->id,
            'action' => 'ci_activity.deleted',
            'module' => 'ci_activities',
            'description' => 'A CI activity was permanently deleted.',
            'metadata' => [
                'activity_id' => $activity->id,
                'co_maker_id' => $activity->co_maker_id,
                'activity_definition_id' => $activity->activity_definition_id,
                'activity_title' => $activity->definition->name,
                'creator_id' => $activity->creator_id,
                'status' => $activity->status->value,
                'deletion_time' => $deletionTime->toISOString(),
            ],
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ]);

        $activity->forceDelete();
    }
}
