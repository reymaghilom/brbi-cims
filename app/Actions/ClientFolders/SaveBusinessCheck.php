<?php

namespace App\Actions\ClientFolders;

use App\Enums\BusinessCheckPhotoCategory;
use App\Exceptions\NoChangesDetectedException;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CiParticipantService;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\ClientMediaUploader;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveBusinessCheck
{
    private const FIELDS = ['income_source_id', 'ci_date', 'location', 'remarks', 'competitor_remarks'];

    public function __construct(
        private readonly ClientMediaUploader $mediaUploader,
        private readonly ResidenceBusinessCheckCompletionEvaluator $completion,
        private readonly CiParticipantService $participants,
        private readonly UpdateBusinessCheckContributors $updateContributors,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function execute(User $actor, ClientFolder $folder, array $data): BusinessCheck
    {
        $checkId = $data['check_id'] ?? null;
        $activePerson = ActivePersonResolver::resolve($folder, $data['co_maker_id'] ?? null);
        // Same cleanup-on-failure convention as SaveResidenceCheck::execute() / UploadMedia::execute():
        // newly stored files (local disk or Cloudinary) live outside the DB transaction below, so a
        // rollback there never removes them — collect every upload this save actually created and
        // delete exactly those if the transaction throws.
        $storedUploads = [];
        // A replaced/removed Cloudinary asset must only ever be destroyed once the DB transaction
        // that stopped using it has actually committed — never from inside it, never if it rolls
        // back — so these are only acted on after DB::transaction() returns successfully below.
        // Local-file removal has no such constraint and keeps running immediately as it already did.
        $retiredCloudAssets = [];

        try {
            $check = DB::transaction(function () use ($actor, $folder, $data, $checkId, $activePerson, &$storedUploads, &$retiredCloudAssets): BusinessCheck {
                $check = $checkId !== null
                    ? $folder->businessChecks()->where('co_maker_id', $activePerson?->id)->findOrFail((int) $checkId)
                    : $folder->businessChecks()->make(['co_maker_id' => $activePerson?->id]);
                $created = ! $check->exists;

                if (! $created && filled($data['expected_updated_at'] ?? null) && ! Carbon::parse($data['expected_updated_at'])->equalTo($check->updated_at)) {
                    throw ValidationException::withMessages([
                        'expected_updated_at' => 'This record has been updated by another CI. Please review the latest version before saving.',
                    ]);
                }

                $check->fill(Arr::only($data, self::FIELDS));
                $fieldsChanged = $check->isDirty(self::FIELDS);
                if ($created) {
                    $check->ci_user_id = $actor->id;
                }
                $check->updated_by = $actor->id;
                $check->save();

                // Business Address and CI Date are shared with this check's linked Business Report
                // (see BusinessCheckController::form()'s $currentLocation/$currentCiDate, which read
                // these same two BusinessReport columns back for display) — saving a Business Check
                // with either value changed keeps that shared source of truth current, the same way
                // Business Report's own save already writes straight to these two columns.
                $businessReport = $check->incomeSource?->businessReport;
                if ($businessReport) {
                    $sharedUpdates = [];
                    if (filled($check->location) && $check->location !== $businessReport->main_business_address) {
                        $sharedUpdates['main_business_address'] = $check->location;
                    }
                    if ($check->ci_date && ($businessReport->start_date === null || ! $check->ci_date->equalTo($businessReport->start_date))) {
                        $sharedUpdates['start_date'] = $check->ci_date;
                    }
                    if ($sharedUpdates !== []) {
                        $businessReport->update($sharedUpdates);
                    }
                }

                $photosRemoved = 0;
                foreach ($data['removed_photo_ids'] ?? [] as $photoId) {
                    $photo = $check->photos()->find((int) $photoId);
                    if ($photo !== null) {
                        if ($photo->isCloud()) {
                            $retiredCloudAssets[] = ['public_id' => $photo->cloud_public_id, 'resource_type' => $photo->cloud_resource_type, 'delivery_type' => $photo->cloud_delivery_type];
                        } else {
                            $this->mediaUploader->deleteLocal($photo->path, $photo->thumbnail_path);
                        }
                        $photo->delete();
                        $photosRemoved++;
                    }
                }

                [$groupsPhotosUploaded, $groupsPhotosRemoved, $groupsChanged] = $this->syncPhotoGroups($folder, $check, $actor, $data['photo_groups'] ?? [], $storedUploads, $retiredCloudAssets);
                $photosRemoved += $groupsPhotosRemoved;

                $mapScreenshotChanged = false;

                // The map screenshot is a single, replace-in-place file (columns on the check itself,
                // not a row in business_check_photos) — same convention as ResidenceCheck's own map
                // screenshot: an explicit remove clears it, and a newly uploaded file always replaces
                // whatever was stored before, freeing the old file.
                if (($data['remove_map_screenshot'] ?? false) && $check->hasMapScreenshot()) {
                    if ($check->hasCloudMapScreenshot()) {
                        $retiredCloudAssets[] = ['public_id' => $check->map_screenshot_cloud_public_id, 'resource_type' => $check->map_screenshot_cloud_resource_type, 'delivery_type' => $check->map_screenshot_cloud_delivery_type];
                    } else {
                        $this->mediaUploader->deleteLocal($check->map_screenshot_path, $check->map_screenshot_thumbnail_path);
                    }
                    $check->fill([
                        'map_screenshot_file_name' => null,
                        'map_screenshot_path' => null,
                        'map_screenshot_thumbnail_path' => null,
                        'map_screenshot_mime_type' => null,
                        'map_screenshot_byte_size' => null,
                        'map_screenshot_uploaded_by' => null,
                        'map_screenshot_cloud_public_id' => null,
                        'map_screenshot_cloud_resource_type' => null,
                        'map_screenshot_cloud_delivery_type' => null,
                        'map_screenshot_cloud_format' => null,
                        'map_screenshot_cloud_width' => null,
                        'map_screenshot_cloud_height' => null,
                    ])->save();
                    $mapScreenshotChanged = true;
                }

                if (isset($data['map_screenshot'])) {
                    if ($check->hasMapScreenshot()) {
                        if ($check->hasCloudMapScreenshot()) {
                            $retiredCloudAssets[] = ['public_id' => $check->map_screenshot_cloud_public_id, 'resource_type' => $check->map_screenshot_cloud_resource_type, 'delivery_type' => $check->map_screenshot_cloud_delivery_type];
                        } else {
                            $this->mediaUploader->deleteLocal($check->map_screenshot_path, $check->map_screenshot_thumbnail_path);
                        }
                    }
                    $stored = $this->mediaUploader->store($folder, $data['map_screenshot'], 'business/map-screenshots', 'map_screenshot');
                    $storedUploads[] = $stored;
                    $check->fill([
                        'map_screenshot_file_name' => $stored['file_name'],
                        'map_screenshot_path' => $stored['path'],
                        'map_screenshot_thumbnail_path' => $stored['thumbnail_path'],
                        'map_screenshot_mime_type' => $stored['mime_type'],
                        'map_screenshot_byte_size' => $stored['byte_size'],
                        'map_screenshot_uploaded_by' => $actor->id,
                        'map_screenshot_cloud_public_id' => $stored['cloud_public_id'],
                        'map_screenshot_cloud_resource_type' => $stored['cloud_resource_type'],
                        'map_screenshot_cloud_delivery_type' => $stored['cloud_delivery_type'],
                        'map_screenshot_cloud_format' => $stored['cloud_format'],
                        'map_screenshot_cloud_width' => $stored['cloud_width'],
                        'map_screenshot_cloud_height' => $stored['cloud_height'],
                    ])->save();
                    $mapScreenshotChanged = true;
                }

                $photosUploaded = $this->storeUploads($folder, $check, $actor, $data['business_photos'] ?? [], BusinessCheckPhotoCategory::Business, $storedUploads)
                    + $this->storeUploads($folder, $check, $actor, $data['competitor_photos'] ?? [], BusinessCheckPhotoCategory::Competitor, $storedUploads)
                    + $groupsPhotosUploaded;

                // The companion CI picker submits contributor_ids alongside every save (see
                // SaveBusinessCheckRequest's contributor_ids_present marker), so its mere presence
                // must not defeat no-change detection — only an actual add/remove counts as a
                // change. This is a read-only preview: UpdateBusinessCheckContributors::execute()
                // ends with $check->refresh(), which is safe to call after $check->save() above
                // (nothing pending would be lost), so the actual sync happens further below.
                $companionIds = array_key_exists('contributor_ids', $data) ? array_map('intval', (array) $data['contributor_ids']) : null;
                $participantsChanged = $companionIds !== null && $this->participants->wouldChangeCompanions($check, $companionIds);

                if (! $created && ! $fieldsChanged && $photosRemoved === 0 && $photosUploaded === 0 && ! $mapScreenshotChanged && ! $participantsChanged && ! $groupsChanged) {
                    throw new NoChangesDetectedException('Nothing changed. No updates were saved to the database.');
                }

                if ($companionIds !== null) {
                    $this->updateContributors->execute($actor, $folder, $check, $companionIds);
                }

                AuditLog::create([
                    'user_id' => $actor->id,
                    'client_folder_id' => $folder->id,
                    'action' => $created ? 'business_check.created' : 'business_check.updated',
                    'module' => 'residence_business_report',
                    'description' => $created ? 'A Business Check was added.' : 'A Business Check was updated.',
                    'metadata' => ['business_check_id' => $check->id, 'income_source_id' => $check->income_source_id, 'co_maker_id' => $activePerson?->id, 'photos_uploaded' => $photosUploaded, 'photos_removed' => $photosRemoved],
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);

                $this->completion->evaluate($folder, $activePerson?->id);

                return $check->refresh();
            });

            // The DB transaction has actually committed at this point — only now is it safe to
            // destroy any Cloudinary asset a replace/remove retired above.
            foreach ($retiredCloudAssets as $asset) {
                $this->mediaUploader->retireCloudAsset($asset['public_id'], $asset['resource_type'], $asset['delivery_type']);
            }

            return $check;
        } catch (\Throwable $exception) {
            foreach ($storedUploads as $stored) {
                $this->mediaUploader->deleteUpload($stored);
            }
            throw $exception;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $storedUploads
     * @param  ?int  $groupId  Scopes both the new rows' own foreign key and the sort_order sequence
     *                         they're appended to — each Photo Group orders its own photos
     *                         independently of every other group (and of ungrouped/competitor
     *                         photos), matching how a group is edited and rendered as its own unit.
     */
    private function storeUploads(ClientFolder $folder, BusinessCheck $check, User $actor, array $files, BusinessCheckPhotoCategory $category, array &$storedUploads, ?int $groupId = null): int
    {
        $scope = $check->photos()->where('category', $category->value);
        $groupId !== null ? $scope->where('business_check_photo_group_id', $groupId) : $scope->whereNull('business_check_photo_group_id');
        $nextSortOrder = ((int) $scope->max('sort_order')) + 1;
        $uploaded = 0;
        foreach ($files as $file) {
            $stored = $this->mediaUploader->store($folder, $file, 'business/photos', 'photo');
            $storedUploads[] = $stored;
            $check->photos()->create([
                'category' => $category,
                'business_check_photo_group_id' => $groupId,
                'file_name' => $stored['file_name'],
                'path' => $stored['path'],
                'thumbnail_path' => $stored['thumbnail_path'],
                'mime_type' => $stored['mime_type'],
                'byte_size' => $stored['byte_size'],
                'checksum' => $stored['checksum'],
                'sort_order' => $nextSortOrder++,
                'uploaded_by' => $actor->id,
                'cloud_public_id' => $stored['cloud_public_id'],
                'cloud_resource_type' => $stored['cloud_resource_type'],
                'cloud_delivery_type' => $stored['cloud_delivery_type'],
                'cloud_format' => $stored['cloud_format'],
                'cloud_width' => $stored['cloud_width'],
                'cloud_height' => $stored['cloud_height'],
            ]);
            $uploaded++;
        }

        return $uploaded;
    }

    /**
     * Syncs the Photo Groups repeater — same id/_delete convention as every other repeater in this
     * codebase (see SaveBusinessIncomeSource::sync()). A brand new, still-empty group (no caption,
     * no new files, no legacy photos to claim) is never persisted at all; an existing group that
     * ends up with nothing after this save (every photo removed, caption cleared) is cleaned up the
     * same way, so a Photo Group row only ever exists while it's actually holding something.
     *
     * @param  list<array<string, mixed>>  $groupsData
     * @param  list<array<string, mixed>>  $storedUploads
     * @param  list<array<string, mixed>>  $retiredCloudAssets
     * @return array{0: int, 1: int, 2: bool} [photosUploaded, photosRemoved, anyGroupChanged]
     */
    private function syncPhotoGroups(ClientFolder $folder, BusinessCheck $check, User $actor, array $groupsData, array &$storedUploads, array &$retiredCloudAssets): array
    {
        $photosUploaded = 0;
        $photosRemoved = 0;
        $changed = false;

        foreach (array_values($groupsData) as $index => $groupData) {
            $id = filled($groupData['id'] ?? null) ? (int) $groupData['id'] : null;
            $delete = filter_var($groupData['_delete'] ?? false, FILTER_VALIDATE_BOOL);
            $existingGroup = $id ? $check->photoGroups()->with('photos')->find($id) : null;

            if ($delete) {
                if ($existingGroup) {
                    foreach ($existingGroup->photos as $photo) {
                        $this->retirePhoto($photo, $retiredCloudAssets);
                        $photosRemoved++;
                    }
                    $existingGroup->delete();
                    $changed = true;
                }

                continue;
            }

            $caption = filled($groupData['caption'] ?? null) ? trim((string) $groupData['caption']) : null;
            $newFiles = $groupData['photos'] ?? [];
            $legacyIds = array_map('intval', (array) ($groupData['legacy_photo_ids'] ?? []));
            $removedIds = array_map('intval', (array) ($groupData['removed_photo_ids'] ?? []));

            $group = $existingGroup ?? $check->photoGroups()->make();

            if ($group->exists) {
                if ($group->caption !== $caption || (int) $group->sort_order !== $index) {
                    $changed = true;
                }
                $group->fill(['caption' => $caption, 'sort_order' => $index]);
                $group->save();

                foreach ($removedIds as $photoId) {
                    $photo = $group->photos()->find($photoId);
                    if ($photo) {
                        $this->retirePhoto($photo, $retiredCloudAssets);
                        $photosRemoved++;
                        $changed = true;
                    }
                }
            } elseif ($caption !== null || $newFiles !== [] || $legacyIds !== []) {
                $group->fill(['caption' => $caption, 'sort_order' => $index]);
                $group->save();
                $changed = true;
            } else {
                continue;
            }

            if ($legacyIds !== []) {
                // A historical ungrouped photo (saved before Photo Groups existed) being claimed
                // into this group — a plain foreign-key backfill, never a re-upload or file move.
                $claimed = \App\Models\BusinessCheckPhoto::query()
                    ->whereIn('id', $legacyIds)
                    ->where('business_check_id', $check->id)
                    ->whereNull('business_check_photo_group_id')
                    ->update(['business_check_photo_group_id' => $group->id]);
                if ($claimed > 0) {
                    $changed = true;
                }
            }

            if ($newFiles !== []) {
                $uploaded = $this->storeUploads($folder, $check, $actor, $newFiles, BusinessCheckPhotoCategory::Business, $storedUploads, $group->id);
                $photosUploaded += $uploaded;
                if ($uploaded > 0) {
                    $changed = true;
                }
            }

            if (blank($group->caption) && $group->photos()->count() === 0) {
                $group->delete();
            }
        }

        return [$photosUploaded, $photosRemoved, $changed];
    }

    private function retirePhoto(\App\Models\BusinessCheckPhoto $photo, array &$retiredCloudAssets): void
    {
        if ($photo->isCloud()) {
            $retiredCloudAssets[] = ['public_id' => $photo->cloud_public_id, 'resource_type' => $photo->cloud_resource_type, 'delivery_type' => $photo->cloud_delivery_type];
        } else {
            $this->mediaUploader->deleteLocal($photo->path, $photo->thumbnail_path);
        }
        $photo->delete();
    }
}
