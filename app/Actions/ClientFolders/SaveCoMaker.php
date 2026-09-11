<?php

namespace App\Actions\ClientFolders;

use App\Exceptions\NoChangesDetectedException;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Facades\DB;

class SaveCoMaker
{
    public function __construct(
        private readonly SeedCiActivities $seedActivities,
        private readonly ClientProgressService $progress,
    ) {}

    /** @param  array{co_maker_id: ?int, first_name: string, middle_name: ?string, last_name: string, suffix: ?string, address?: ?string}  $data */
    public function execute(User $actor, ClientFolder $folder, array $data): CoMaker
    {
        return DB::transaction(function () use ($actor, $folder, $data): CoMaker {
            $coMakerId = $data['co_maker_id'] ?? null;
            // full_name stays a stored, derived column — every existing reader (tab labels,
            // report/export data, audit logs) keeps working unchanged; only the Add/Edit form
            // itself deals in separate name parts. relationship_to_applicant/contact_number are
            // intentionally left out of $fields below — the Add/Edit modal doesn't collect them,
            // and any values already saved on an existing co-maker (from before that change) must
            // survive being untouched by a later edit. Address, unlike those two, is collected by
            // the modal again (Residence Check needs a real address to resolve), so it's back in
            // $fields and in the dirty-check below.
            $fullName = collect([$data['first_name'], $data['middle_name'] ?? null, $data['last_name'], $data['suffix'] ?? null])
                ->filter(fn (?string $value): bool => filled($value))
                ->implode(' ');
            $fields = [
                'first_name' => $data['first_name'],
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => $data['last_name'],
                'suffix' => $data['suffix'] ?? null,
                'full_name' => $fullName,
                'last_edited_by' => $actor->id,
            ];
            // Address only moves when the request actually carried the field. The Add form no
            // longer collects it (a new co-maker simply starts without one), and omitting the key
            // on an edit must leave an already-saved address untouched rather than blanking it.
            if (array_key_exists('address', $data)) {
                $fields['address'] = $data['address'];
            }

            // An id means editing one specific, already-saved co-maker (scoped to this folder by
            // SaveCoMakerRequest's validation) — never a new record, so this can never duplicate
            // an existing co-maker no matter how many the folder already has.
            if ($coMakerId !== null) {
                $coMaker = $folder->coMakers()->findOrFail($coMakerId);
                $coMaker->fill($fields);
                if (! $coMaker->isDirty(['first_name', 'middle_name', 'last_name', 'suffix', 'full_name', 'address'])) {
                    throw new NoChangesDetectedException;
                }
                $coMaker->save();
            } else {
                $coMaker = $folder->coMakers()->create($fields);
                // A brand-new co-maker needs their own CI Activities checklist immediately,
                // mirroring how the Applicant's is seeded when the folder itself is created —
                // there is no "add activity" UI, so without this a new co-maker would have none.
                $this->seedActivities->execute($folder, $coMaker, $actor);
                // A new Co-Maker adds their own four mandatory requirements to the folder.
                $this->progress->recalculate($folder);
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => $coMakerId !== null ? 'co_maker.updated' : 'co_maker.added',
                'module' => 'client_folders',
                'description' => $coMakerId !== null ? 'A co-maker was updated.' : 'A co-maker was added.',
                'metadata' => ['co_maker_id' => $coMaker->id, 'full_name' => $coMaker->full_name],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            return $coMaker;
        });
    }
}
