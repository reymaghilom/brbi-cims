<?php

namespace App\Actions\ClientFolders;

use App\Actions\Media\AddCiActivityProofPhotos;
use App\Enums\ActivityStatus;
use App\Models\CiActivity;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitCiActivities
{
    public function __construct(
        private readonly AddCiActivityProofPhotos $addProofPhotos,
        private readonly SubmitCiActivity $submit,
    ) {}

    /**
     * @param  Collection<int, CiActivity>  $activities
     * @param  array<int, array<int, UploadedFile>>  $proofs  keyed by activity id
     */
    public function execute(
        User $actor,
        ClientFolder $folder,
        ?CoMaker $activePerson,
        Collection $activities,
        array $proofs,
        ?string $submittedTo,
        ?string $submissionNote,
    ): void {
        $this->ensureExactEligibility($folder, $activePerson, $activities);

        foreach ($activities as $activity) {
            $files = $proofs[$activity->id] ?? [];
            if (! is_array($files) || $files === []) {
                continue;
            }

            $this->addProofPhotos->execute($actor, $folder, $activity, $files);
        }

        foreach ($activities as $activity) {
            $this->submit->execute($actor, $folder, $activity, [
                'submitted_to' => $submittedTo,
                'submission_note' => $submissionNote,
            ]);
        }
    }

    /** @param  Collection<int, CiActivity>  $activities */
    private function ensureExactEligibility(ClientFolder $folder, ?CoMaker $activePerson, Collection $activities): void
    {
        DB::transaction(function () use ($folder, $activePerson, $activities): void {
            foreach ($activities as $activity) {
                $locked = CiActivity::query()->whereKey($activity->id)->lockForUpdate()->firstOrFail();
                $isExact = $locked->client_folder_id === $folder->id
                    && $locked->co_maker_id === $activePerson?->id
                    && $locked->status === ActivityStatus::Completed;

                if (! $isExact) {
                    throw ValidationException::withMessages([
                        'activity_ids' => 'Only exact, completed CI activities for the current Applicant/Co-Maker can be submitted.',
                    ])->errorBag('submission');
                }
            }
        });
    }
}
