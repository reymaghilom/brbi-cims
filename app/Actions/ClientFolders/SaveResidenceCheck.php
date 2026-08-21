<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\PrivateMediaStorage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveResidenceCheck
{
    public function __construct(
        private readonly PrivateMediaStorage $storage,
        private readonly ResidenceBusinessCheckCompletionEvaluator $completion,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function execute(User $actor, ClientFolder $folder, array $data): ResidenceCheck
    {
        $checkId = $data['check_id'] ?? null;
        $activePerson = ActivePersonResolver::resolve($folder, $data['co_maker_id'] ?? null);

        return DB::transaction(function () use ($actor, $folder, $data, $checkId, $activePerson): ResidenceCheck {
            $check = $checkId !== null
                ? $folder->residenceChecks()->where('co_maker_id', $activePerson?->id)->findOrFail((int) $checkId)
                : $folder->residenceChecks()->make(['co_maker_id' => $activePerson?->id]);
            $created = ! $check->exists;

            $check->fill(Arr::only($data, ['ci_date', 'location', 'remarks', 'google_maps_link']));
            if ($created) {
                $check->ci_user_id = $actor->id;
            }
            $check->save();

            foreach ($data['removed_photo_ids'] ?? [] as $photoId) {
                $photo = $check->photos()->find((int) $photoId);
                if ($photo !== null) {
                    $this->storage->deleteStoredFiles([$photo->path, $photo->thumbnail_path]);
                    $photo->delete();
                }
            }

            $nextSortOrder = ((int) $check->photos()->max('sort_order')) + 1;
            foreach ($data['photos'] ?? [] as $file) {
                $stored = $this->storage->store($folder, $file);
                $check->photos()->create([
                    'file_name' => $stored['file_name'],
                    'path' => $stored['temporary_local_path'],
                    'thumbnail_path' => $stored['thumbnail_path'],
                    'mime_type' => $stored['mime_type'],
                    'byte_size' => $stored['byte_size'],
                    'checksum' => $stored['checksum'],
                    'sort_order' => $nextSortOrder++,
                    'uploaded_by' => $actor->id,
                ]);
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => $created ? 'residence_check.created' : 'residence_check.updated',
                'module' => 'residence_business_report',
                'description' => $created ? 'A Residence Check was added.' : 'A Residence Check was updated.',
                'metadata' => ['residence_check_id' => $check->id, 'co_maker_id' => $activePerson?->id],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $this->completion->evaluate($folder, $activePerson?->id);

            return $check->refresh();
        });
    }
}
