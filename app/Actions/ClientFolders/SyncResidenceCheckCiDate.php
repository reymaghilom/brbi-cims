<?php

namespace App\Actions\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Services\ClientFolders\PersonCiDateResolver;
use Illuminate\Support\Facades\DB;

/**
 * Keeps every saved Residence Check's `ci_date` synchronized with whichever "Start Date of CI"
 * is currently authoritative for its person (PersonCiDateResolver) — a Residence Check reuses
 * the CI/BI Report's own Start Date rather than asking the CI to re-encode it, and that value
 * must always reflect the CI/BI Report's CURRENT date, never a frozen snapshot from when the
 * Residence Check was first encoded. Mirrors SyncResidenceCheckLocation's architecture exactly.
 *
 * A bare DB update (not the Eloquent model) deliberately touches only the `ci_date` column — no
 * `updated_at`/`updated_by` bump and no audit log entry, since this is an automatic side effect
 * of a save made elsewhere (the CI/BI Report, or the Residence Check's own save), not a new edit
 * of the Residence Check itself.
 */
class SyncResidenceCheckCiDate
{
    public function execute(ClientFolder $folder, ?CoMaker $activePerson): void
    {
        $ciDate = PersonCiDateResolver::resolve($folder, $activePerson);
        // Never invent or blank out a CI Date — if there's currently nothing authoritative to
        // sync to, leave whatever is already saved untouched rather than destroying it.
        if (! $ciDate) {
            return;
        }

        DB::table('residence_checks')
            ->where('client_folder_id', $folder->id)
            ->where('co_maker_id', $activePerson?->id)
            ->update(['ci_date' => $ciDate->toDateString()]);
    }
}
