<?php

namespace App\Actions\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Services\ClientFolders\PersonAddressResolver;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a Co-Maker's saved Residence Check `location` synchronized with their current address.
 * Applicant Residence Location is independently editable report data, so Applicant calls are
 * intentional no-ops: other Applicant address sources may only prefill a new Residence form.
 *
 * A Co-Maker bare DB update (not the Eloquent model) touches only the `location` column — no
 * `updated_at`/`updated_by` bump and no audit log entry, since this is an automatic side effect
 * of a save made elsewhere, not a new edit of the Residence Check itself, and attributing it to
 * whichever actor happened to trigger it would be misleading.
 */
class SyncResidenceCheckLocation
{
    public function execute(ClientFolder $folder, ?CoMaker $activePerson): void
    {
        // Applicant Residence Location is an independently editable saved report value. Applicant
        // address sources may prefill a new form, but must never silently rewrite an existing check.
        // Co-Maker behavior remains unchanged until its own workflow is explicitly revised.
        if (! $activePerson) {
            return;
        }

        $address = PersonAddressResolver::resolve($folder, $activePerson);
        // Never invent or blank out a location — if there's currently nothing authoritative to
        // sync to, leave whatever is already saved untouched rather than destroying it.
        if (blank($address)) {
            return;
        }

        DB::table('residence_checks')
            ->where('client_folder_id', $folder->id)
            ->where('co_maker_id', $activePerson?->id)
            ->update(['location' => $address]);
    }
}
