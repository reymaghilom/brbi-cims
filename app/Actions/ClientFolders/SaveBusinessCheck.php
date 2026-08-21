<?php

namespace App\Actions\ClientFolders;

use App\Enums\BusinessCheckPhotoCategory;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\PrivateMediaStorage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SaveBusinessCheck
{
    public function __construct(
        private readonly PrivateMediaStorage $storage,
        private readonly ResidenceBusinessCheckCompletionEvaluator $completion,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function execute(User $actor, ClientFolder $folder, array $data): BusinessCheck
    {
        $checkId = $data['check_id'] ?? null;
        $activePerson = ActivePersonResolver::resolve($folder, $data['co_maker_id'] ?? null);

        return DB::transaction(function () use ($actor, $folder, $data, $checkId, $activePerson): BusinessCheck {
            $check = $checkId !== null
                ? $folder->businessChecks()->where('co_maker_id', $activePerson?->id)->findOrFail((int) $checkId)
                : $folder->businessChecks()->make(['co_maker_id' => $activePerson?->id]);
            $created = ! $check->exists;

            $check->fill(Arr::only($data, ['income_source_id', 'ci_date', 'location', 'remarks', 'competitor_remarks', 'google_maps_link']));
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

            $this->storeUploads($folder, $check, $actor, $data['business_photos'] ?? [], BusinessCheckPhotoCategory::Business);
            $this->storeUploads($folder, $check, $actor, $data['competitor_photos'] ?? [], BusinessCheckPhotoCategory::Competitor);

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => $created ? 'business_check.created' : 'business_check.updated',
                'module' => 'residence_business_report',
                'description' => $created ? 'A Business Check was added.' : 'A Business Check was updated.',
                'metadata' => ['business_check_id' => $check->id, 'income_source_id' => $check->income_source_id, 'co_maker_id' => $activePerson?->id],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $this->completion->evaluate($folder, $activePerson?->id);

            return $check->refresh();
        });
    }

    private function storeUploads(ClientFolder $folder, BusinessCheck $check, User $actor, array $files, BusinessCheckPhotoCategory $category): void
    {
        $nextSortOrder = ((int) $check->photos()->where('category', $category->value)->max('sort_order')) + 1;
        foreach ($files as $file) {
            $stored = $this->storage->store($folder, $file);
            $check->photos()->create([
                'category' => $category,
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
    }
}
