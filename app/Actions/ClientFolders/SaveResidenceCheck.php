<?php

namespace App\Actions\ClientFolders;

use App\Exceptions\NoChangesDetectedException;
use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CiParticipantService;
use App\Services\ClientFolders\PersonAddressResolver;
use App\Services\ClientFolders\PersonCiDateResolver;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\ClientMediaUploader;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveResidenceCheck
{
    public function __construct(
        private readonly ClientMediaUploader $mediaUploader,
        private readonly ResidenceBusinessCheckCompletionEvaluator $completion,
        private readonly CiParticipantService $participants,
        private readonly UpdateResidenceCheckContributors $updateContributors,
        private readonly ClientProgressService $progress,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function execute(User $actor, ClientFolder $folder, array $data): ResidenceCheck
    {
        $checkId = $data['check_id'] ?? null;
        $activePerson = ActivePersonResolver::resolve($folder, $data['co_maker_id'] ?? null);

        if ($checkId !== null) {
            return $this->save($actor, $folder, $data, (int) $checkId, $activePerson);
        }

        $requestToken = $data['request_token'] ?? null;
        if (! $requestToken) {
            // No token at all (e.g. a request that predates this field, or a caller that never
            // sends one) — nothing to dedup against, so this falls straight through to the plain
            // create path exactly as before.
            return $this->save($actor, $folder, $data, null, $activePerson);
        }

        // request_token identifies one loaded copy of the Add Residence Check form (a fresh UUID
        // rendered once per page/iframe load — see the hidden input in
        // residence-checks/form.blade.php), never the folder/person/CI. That's what makes this safe
        // to key a lock on: two submits sharing the same token are necessarily the same save
        // (double-click, a retried request) acted on twice, while two genuinely separate Add
        // Residence Check actions for the same person — a legitimate, already-supported case (see
        // test_multiple_residence_checks_for_the_same_applicant_appear_as_separate_rows) — always
        // reload the form first and so always carry different tokens, never colliding here.
        $lockKey = "residence-check-create:{$requestToken}";
        $resultCacheKey = "residence-check-create-result:{$requestToken}";

        return Cache::lock($lockKey, 15)->block(10, function () use ($actor, $folder, $data, $activePerson, $resultCacheKey): ResidenceCheck {
            $alreadyCreatedId = Cache::get($resultCacheKey);
            if ($alreadyCreatedId !== null) {
                // The first request for this exact token already created and committed the check
                // (and uploaded its media) while this one was waiting on the lock — answer with that
                // same record instead of creating (and re-uploading into) a second one.
                return $folder->residenceChecks()->findOrFail($alreadyCreatedId);
            }

            $check = $this->save($actor, $folder, $data, null, $activePerson);
            // Cached only after save() returns successfully — a failed attempt (validation, a cloud
            // upload failure, etc.) leaves nothing here, so a genuine retry with the same token
            // reaches save() fresh rather than being told it "already exists".
            Cache::put($resultCacheKey, $check->id, now()->addMinutes(2));

            return $check;
        });
    }

    /** @param  array<string, mixed>  $data */
    private function save(User $actor, ClientFolder $folder, array $data, ?int $checkId, ?CoMaker $activePerson): ResidenceCheck
    {
        // Newly stored map screenshot/photo files (local disk or Cloudinary — see
        // ClientMediaUploader::store()) live outside the DB transaction below, so a rollback there
        // never removes them on its own. Same cleanup-on-failure convention as UploadMedia::execute():
        // collect every upload this save actually created, and if the transaction throws for any
        // reason, delete exactly those (never anything that existed before this call).
        $storedUploads = [];
        // A replaced/removed Cloudinary asset must only ever be destroyed once the DB transaction
        // that stopped using it has actually committed — never from inside it, and never if it
        // rolls back — so these are collected here and only acted on after DB::transaction()
        // returns successfully below. Local-file removal has no such constraint and keeps running
        // immediately inside the transaction exactly as it already did.
        $retiredCloudAssets = [];

        try {
            $check = DB::transaction(function () use ($actor, $folder, $data, $checkId, $activePerson, &$storedUploads, &$retiredCloudAssets): ResidenceCheck {
                $check = $checkId !== null
                    ? $folder->residenceChecks()->where('co_maker_id', $activePerson?->id)->findOrFail((int) $checkId)
                    : $folder->residenceChecks()->make(['co_maker_id' => $activePerson?->id]);
                $created = ! $check->exists;
                $resolvedAddress = PersonAddressResolver::resolve($folder, $activePerson);
                $resolvedCiDate = PersonCiDateResolver::resolve($folder, $activePerson);

                if (! $created && filled($data['expected_updated_at'] ?? null) && ! Carbon::parse($data['expected_updated_at'])->equalTo($check->updated_at)) {
                    throw ValidationException::withMessages([
                        'expected_updated_at' => 'This record has been updated by another CI. Please review the latest version before saving.',
                    ]);
                }

                // Browser forms always submit this required editable field. Older direct callers
                // may omit it, so retain the existing saved snapshot on update or use the exact
                // person's resolver value on create; never borrow another person's address.
                $location = filled($data['location'] ?? null)
                    ? trim((string) $data['location'])
                    : ($check->location ?: $resolvedAddress);
                if (blank($location)) {
                    throw ValidationException::withMessages([
                        'location' => 'The Location field is required.',
                    ]);
                }

                // Same prefill-before-save / independent-after-save precedence as Location: an
                // explicitly submitted value always wins, then this check's own already-saved
                // date, then (create only, since an existing check always already has one) the
                // exact person's current CI/BI Start Date as a one-time prefill source.
                $ciDate = filled($data['ci_date'] ?? null)
                    ? Carbon::parse($data['ci_date'])
                    : ($check->ci_date ?: $resolvedCiDate);
                if (blank($ciDate)) {
                    throw ValidationException::withMessages([
                        'ci_date' => 'The CI Date field is required.',
                    ]);
                }

                $check->fill(Arr::only($data, ['remarks', 'google_maps_link']));
                $check->location = $location;
                // residence_checks.ci_date is NOT NULL — always set directly, never left to a
                // separate post-save sync (that would silently overwrite an already-saved date
                // whenever the CI/BI Report changes later, which violates independence-after-save).
                $check->ci_date = $ciDate instanceof Carbon ? $ciDate->toDateString() : $ciDate;

                // A genuine no-op edit (nothing the CI touched, and nothing an authoritative source
                // needed to sync) must skip the save entirely — no timestamp bump, no audit entry, no
                // file writes. ci_date is included here as a real field (it's assigned above just like
                // any other), so a save is only ever considered a no-op when that resolved value
                // already matches what's persisted. Applicant location is included because this
                // Action now saves that editable report field directly.
                // The companion CI picker submits contributor_ids alongside every save (see
                // SaveResidenceCheckRequest's contributor_ids_present marker), so its mere presence
                // must not defeat no-change detection — only an actual add/remove counts as a
                // change. wouldChangeCompanions() is read-only (never mutates the pivot), so this is
                // safe to check before $check even exists in the database yet; the actual sync
                // happens further below, after $check->save().
                $companionIds = array_key_exists('contributor_ids', $data) ? array_map('intval', (array) $data['contributor_ids']) : null;
                $participantsChanged = $companionIds !== null && $this->participants->wouldChangeCompanions($check, $companionIds);

                if (! $created) {
                    // remarks/google_maps_link are always present in the submitted payload (the form
                    // fields exist even when empty), so a never-filled-in field round-trips as '' —
                    // compared naively against a persisted null, isDirty() would wrongly call that a
                    // change on every single no-op save. Blank-normalizing both sides first treats ''
                    // and null as equivalent, matching what the CI actually perceives on screen.
                    $normalize = fn (?string $value): ?string => blank($value) ? null : $value;
                    $textFieldsChanged = $normalize($check->remarks) !== $normalize($check->getOriginal('remarks'))
                        || $normalize($check->google_maps_link) !== $normalize($check->getOriginal('google_maps_link'));

                    $newPhotoCount = count($data['photos'] ?? []);
                    $removedPhotoCount = $check->photos()->whereIn('id', $data['removed_photo_ids'] ?? [])->count();
                    $mapScreenshotChanged = isset($data['map_screenshot'])
                        || (($data['remove_map_screenshot'] ?? false) && $check->map_screenshot_path);

                    if (! $textFieldsChanged && ! $check->isDirty(['ci_date', 'location']) && $newPhotoCount === 0 && $removedPhotoCount === 0 && ! $mapScreenshotChanged && ! $participantsChanged) {
                        throw new NoChangesDetectedException('Nothing changed. No updates were saved to the database.');
                    }
                }

                if ($created) {
                    $check->ci_user_id = $actor->id;
                }
                $check->updated_by = $actor->id;
                $check->save();

                // Companion CIs must exist in the database immediately after this very save — never
                // deferred to a later Update — so this runs unconditionally here for both a brand-new
                // check ($created) and an edit, right after $check->save() gives a create its id.
                // UpdateResidenceCheckContributors::execute() ends with $check->refresh(), which is
                // safe this early (nothing pending on $check would be lost). Primary CI exclusion,
                // duplicate prevention, and saved order/position all come from
                // CiParticipantService::syncCompanions() itself — never duplicated here.
                if ($companionIds !== null) {
                    $this->updateContributors->execute($actor, $folder, $check, $companionIds);
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

                // The map screenshot is a single, replace-in-place file (columns on the check itself,
                // not a row in residence_check_photos): an explicit remove clears it, and a newly
                // uploaded file always replaces whatever was stored before, freeing the old file.
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
                }

                if (isset($data['map_screenshot'])) {
                    if ($check->hasMapScreenshot()) {
                        if ($check->hasCloudMapScreenshot()) {
                            $retiredCloudAssets[] = ['public_id' => $check->map_screenshot_cloud_public_id, 'resource_type' => $check->map_screenshot_cloud_resource_type, 'delivery_type' => $check->map_screenshot_cloud_delivery_type];
                        } else {
                            $this->mediaUploader->deleteLocal($check->map_screenshot_path, $check->map_screenshot_thumbnail_path);
                        }
                    }
                    $stored = $this->mediaUploader->store($folder, $data['map_screenshot'], 'residence/map-screenshots', 'map_screenshot', $check->co_maker_id === null, $activePerson);
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
                }

                $photosUploaded = 0;
                $nextSortOrder = ((int) $check->photos()->max('sort_order')) + 1;
                foreach ($data['photos'] ?? [] as $file) {
                    $stored = $this->mediaUploader->store($folder, $file, 'residence/photos', 'photo', $check->co_maker_id === null, $activePerson);
                    $storedUploads[] = $stored;
                    $check->photos()->create([
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
                    $photosUploaded++;
                }

                AuditLog::create([
                    'user_id' => $actor->id,
                    'client_folder_id' => $folder->id,
                    'action' => $created ? 'residence_check.created' : 'residence_check.updated',
                    'module' => 'residence_business_report',
                    'description' => $created ? 'A Residence Check was added.' : 'A Residence Check was updated.',
                    'metadata' => ['residence_check_id' => $check->id, 'co_maker_id' => $activePerson?->id, 'photos_uploaded' => $photosUploaded, 'photos_removed' => $photosRemoved],
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);

                $this->completion->evaluate($folder, $activePerson?->id);
                $this->progress->recalculate($folder);

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
}
