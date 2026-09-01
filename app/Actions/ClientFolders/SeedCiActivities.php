<?php

namespace App\Actions\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SeedCiActivities
{
    /** @return array{created: int, restored: int, already_active: int} */
    public function execute(ClientFolder $folder, ?CoMaker $person = null, ?User $actor = null): array
    {
        return DB::transaction(function () use ($folder, $person, $actor): array {
            ClientFolder::query()->whereKey($folder->id)->lockForUpdate()->firstOrFail();
            if ($person !== null) {
                $folder->coMakers()->whereKey($person->id)->firstOrFail();
            }

            $definitions = ActivityDefinition::query()
                ->where('is_active', true)
                ->whereIn('code', [
                    ActivityDefinition::BARANGAY_CHECK_CODE,
                    ActivityDefinition::NEIGHBOR_CHECK_CODE,
                ])
                ->orderBy('sort_order')
                ->get();
            $created = 0;
            $restored = 0;
            $alreadyActive = 0;

            foreach ($definitions as $definition) {
                $activityQuery = CiActivity::query()
                    ->where('client_folder_id', $folder->id)
                    ->where('co_maker_id', $person?->id)
                    ->where('activity_definition_id', $definition->id);

                if ((clone $activityQuery)->lockForUpdate()->exists()) {
                    $alreadyActive++;

                    continue;
                }

                $trashedActivity = (clone $activityQuery)
                    ->onlyTrashed()
                    ->latest('deleted_at')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
                if ($trashedActivity !== null) {
                    $trashedActivity->forceFill([
                        'status' => ActivityStatus::Pending,
                        'scheduled_at' => null,
                        'scheduled_has_time' => false,
                        'reminder_sent_at' => null,
                        'remarks' => null,
                        'completed_at' => null,
                        'submitted_at' => null,
                        'submitted_by' => null,
                        'submission_note' => null,
                    ])->save();
                    $trashedActivity->restore();
                    $restored++;

                    continue;
                }

                CiActivity::create([
                    'client_folder_id' => $folder->id,
                    'co_maker_id' => $person?->id,
                    'activity_definition_id' => $definition->id,
                    'name' => $definition->name,
                    'status' => ActivityStatus::Pending,
                    'scheduled_at' => null,
                    'scheduled_has_time' => false,
                    'remarks' => null,
                    'assigned_ci_id' => $folder->assigned_ci_id,
                    'creator_id' => $actor?->id,
                    'updated_by' => $actor?->id,
                ]);
                $created++;
            }

            return ['created' => $created, 'restored' => $restored, 'already_active' => $alreadyActive];
        });
    }
}
