<?php

namespace Tests\Concerns;

use App\Enums\ActivityStatus;
use App\Models\ActivityDefinition;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A test fixture for the two canonical default checks.
 *
 * Barangay Check and Neighbor Check are manual built-in Activity Types now: no production code
 * creates them, so nothing in the application can stand in as test setup. The tests that use this
 * are about what happens to those rows ONCE THEY EXIST - completion, audit, history, reset,
 * deletion protection - not about how they came to exist, so they say so explicitly here rather
 * than leaning on a production seeding path that no longer has any business existing.
 *
 * It writes the same shape the retired SeedCiActivities action used to, so the assertions those
 * tests already make about creator/updated_by/assigned_ci_id still describe the same rows.
 */
trait CreatesDefaultCiActivities
{
    /**
     * The Barangay Check and Neighbor Check rows for one exact person, in definition order.
     *
     * @return Collection<int, CiActivity>
     */
    protected function createDefaultCiActivities(ClientFolder $folder, ?CoMaker $person = null, ?User $actor = null): Collection
    {
        return ActivityDefinition::query()
            ->whereIn('code', [
                ActivityDefinition::BARANGAY_CHECK_CODE,
                ActivityDefinition::NEIGHBOR_CHECK_CODE,
            ])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ActivityDefinition $definition): CiActivity => CiActivity::create([
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
            ]));
    }
}
