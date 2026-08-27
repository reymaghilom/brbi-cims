<?php

namespace App\Actions\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Services\ClientFolders\PersonAddressResolver;
use Illuminate\Support\Facades\DB;

/**
 * Keeps every saved Residence Check's `location` synchronized with whichever address is
 * currently authoritative for its person (PersonAddressResolver) — the business rule is that a
 * Residence Report always reflects the person's CURRENT address, never a frozen snapshot from
 * when it was first encoded. Call this after anything that can change that authoritative address
 * (saving a Residence Check itself, a CI/BI Report, Client Information's addresses, or a
 * Co-Maker) so nobody ever needs to reopen and re-save a Residence Check just to pick up an
 * address correction.
 *
 * A bare DB update (not the Eloquent model) deliberately touches only the `location` column — no
 * `updated_at`/`updated_by` bump and no audit log entry, since this is an automatic side effect
 * of a save made elsewhere, not a new edit of the Residence Check itself, and attributing it to
 * whichever actor happened to trigger it would be misleading.
 */
class SyncResidenceCheckLocation
{
    public function execute(ClientFolder $folder, ?CoMaker $activePerson): void
    {
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
