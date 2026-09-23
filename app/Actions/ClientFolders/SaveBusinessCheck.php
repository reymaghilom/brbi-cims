<?php

namespace App\Actions\ClientFolders;

use App\Enums\BusinessCheckPhotoCategory;
use App\Exceptions\BusinessCheckConflictException;
use App\Exceptions\NoChangesDetectedException;
use App\Exceptions\SimilarBusinessCheckExistsException;
use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\BusinessCheckPhoto;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\MediaReference;
use App\Models\User;
use App\Services\ClientFolders\ActivePersonResolver;
use App\Services\ClientFolders\CiParticipantService;
use App\Services\ClientFolders\ClientFolderFileCleanup;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\ClientMediaUploader;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveBusinessCheck
{
    private const FIELDS = ['income_source_id', 'business_name', 'ci_date', 'location', 'remarks', 'competitor_remarks'];

    public function __construct(
        private readonly ClientMediaUploader $mediaUploader,
        private readonly ResidenceBusinessCheckCompletionEvaluator $completion,
        private readonly CiParticipantService $participants,
        private readonly UpdateBusinessCheckContributors $updateContributors,
        private readonly ClientProgressService $progress,
        private readonly ClientFolderFileCleanup $fileCleanup,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function execute(User $actor, ClientFolder $folder, array $data): BusinessCheck
    {
        $checkId = $data['check_id'] ?? null;
        $activePerson = ActivePersonResolver::resolve($folder, $data['co_maker_id'] ?? null);

        // request_token identifies one loaded copy of the Add Business Check form (a fresh UUID
        // rendered once per page/iframe load), never the folder/person/business — so two submits
        // carrying the same token are necessarily one Add attempt acted on twice (double-click, a
        // retried request), while two genuinely separate Add actions always reload the form and so
        // always carry different tokens. Same convention SaveResidenceCheck::execute() already
        // uses. Edits are never deduped this way: they are already scoped by check_id and carry
        // their own expected_revision guard.
        $requestToken = $checkId === null ? ($data['request_token'] ?? null) : null;
        if (! $requestToken) {
            return $this->createOrUpdate($actor, $folder, $data, $checkId, $activePerson);
        }

        return Cache::lock("business-check-create:{$requestToken}", 15)->block(10, function () use ($actor, $folder, $data, $activePerson, $requestToken): BusinessCheck {
            $resultCacheKey = "business-check-create-result:{$requestToken}";
            $alreadyCreatedId = Cache::get($resultCacheKey);
            if ($alreadyCreatedId !== null) {
                // The first request for this exact token already created and committed its check
                // (and uploaded its media) while this one waited on the lock — answer with that
                // same record instead of inserting a second one.
                return $folder->businessChecks()->findOrFail($alreadyCreatedId);
            }

            $check = $this->createOrUpdate($actor, $folder, $data, null, $activePerson);
            // Recorded only after a successful create. A failed attempt — validation, a cloud
            // upload failure, and in particular a SimilarBusinessCheckExistsException, which
            // deliberately creates nothing — leaves nothing here, so the very next submission of
            // this same still-open form (a genuine retry, or Continue Anyway) reaches the create
            // path fresh rather than being told it already succeeded.
            Cache::put($resultCacheKey, $check->id, now()->addMinutes(2));

            return $check;
        });
    }

    /**
     * The similarity signature is deliberately narrow and exact-match-after-normalization: the
     * same Business Name AND the same Location AND the same CI Date. Business Name alone is never
     * the signal — two genuinely different branches or stalls routinely share a name ("Sari-Sari
     * Store"), and treating that as a duplicate would block real work. Normalization is limited to
     * trimming, collapsing runs of whitespace and case-insensitive comparison; there is no fuzzy
     * or AI matching, so the outcome is predictable and explainable to a CI.
     *
     * Scope is the exact person inside this exact folder, with income_source_id NULL, so a manual
     * check can never be compared against another person's, another folder's, or a linked one.
     *
     * @param  array<string, mixed>  $data
     */
    private function findSimilarManualCheck(ClientFolder $folder, ?CoMaker $activePerson, array $data): ?BusinessCheck
    {
        $ciDate = filled($data['ci_date'] ?? null) ? Carbon::parse($data['ci_date'])->toDateString() : null;
        $name = $this->normalizeForComparison($data['business_name'] ?? null);
        $location = $this->normalizeForComparison($data['location'] ?? null);
        if ($ciDate === null || $name === '' || $location === '') {
            return null;
        }

        return $folder->businessChecks()
            ->where('co_maker_id', $activePerson?->id)
            ->whereNull('income_source_id')
            ->whereDate('ci_date', $ciDate)
            ->get(['id', 'business_name', 'location'])
            ->first(fn (BusinessCheck $check) => $this->normalizeForComparison($check->business_name) === $name
                && $this->normalizeForComparison($check->location) === $location);
    }

    /** Trim, collapse internal whitespace runs, casefold — nothing cleverer, so two CIs can predict it. */
    private function normalizeForComparison(?string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
    }

    /** @param  array<string, mixed>  $data */
    private function createOrUpdate(User $actor, ClientFolder $folder, array $data, ?int $checkId, ?CoMaker $activePerson): BusinessCheck
    {
        // Same cleanup-on-failure convention as SaveResidenceCheck::execute() / UploadMedia::execute():
        // newly stored files (local disk or Cloudinary) live outside the DB transaction below, so a
        // rollback there never removes them — collect every upload this save actually created and
        // delete exactly those if the transaction throws.
        $storedUploads = [];
        // A replaced/removed Cloudinary asset must only ever be destroyed once the DB transaction
        // that stopped using it has actually committed — never from inside it, never if it rolls
        // back — so these are only acted on after DB::transaction() returns successfully below.
        $retiredCloudAssets = [];
        $retiredLocalPaths = [];
        $cleanupTaskIds = [];

        try {
            $check = DB::transaction(function () use ($actor, $folder, $data, $checkId, $activePerson, &$storedUploads, &$retiredCloudAssets, &$retiredLocalPaths, &$cleanupTaskIds): BusinessCheck {
                // Referencing an existing business is optional. A manual Business Check (no
                // income_source_id) has no business row to serialize against and no
                // one-check-per-business rule to enforce, so both guards below apply only to a
                // check that actually references a business.
                $referencedSourceId = filled($data['income_source_id'] ?? null) ? (int) $data['income_source_id'] : null;
                if ($checkId === null && $referencedSourceId !== null) {
                    // Serialize creates for this exact scoped business, then re-check inside the
                    // transaction so a forged or concurrent request cannot insert a duplicate.
                    $folder->incomeSources()
                        ->where('co_maker_id', $activePerson?->id)
                        ->whereKey($referencedSourceId)
                        ->lockForUpdate()
                        ->firstOrFail();
                    // Authoritative on the exact income_source_id alone — one IncomeSource may be
                    // linked to at most one Business Check, whichever check that is.
                    if (BusinessCheck::query()->where('income_source_id', $referencedSourceId)->exists()) {
                        throw ValidationException::withMessages([
                            'income_source_id' => 'A Business Check already exists for the selected business. Open the existing Business Check to view or edit it.',
                        ]);
                    }
                }

                // Manual create only (no referenced business). A person may legitimately run
                // several businesses, so this is never a uniqueness rule — it is an advisory
                // warning against an accidental re-add that the request_token guard cannot catch,
                // because the CI reloaded the form and so carries a different token. Continue
                // Anyway resubmits with allow_similar_duplicate and creates the second check.
                // It deliberately does NOT apply to a linked check: there, the exact
                // income_source_id rule above stays a hard block with no bypass.
                if ($checkId === null && $referencedSourceId === null && ! ($data['allow_similar_duplicate'] ?? false)) {
                    $existing = $this->findSimilarManualCheck($folder, $activePerson, $data);
                    if ($existing !== null) {
                        // Thrown before any field write, photo group, upload, suppression-marker
                        // clear, audit row or progress recalculation — a warning has no side effects.
                        throw new SimilarBusinessCheckExistsException((int) $existing->id);
                    }
                }
                // An existing check is locked before its revision is compared and stays locked
                // through every field, photo group, map screenshot, contributor, audit and
                // progress mutation below, so the compare and the write are one atomic step. The
                // previous updated_at comparison read the row without a lock, so two CIs could
                // both compare against the same value before either wrote and the later request
                // silently overwrote the earlier one. The scope — this folder, this exact person,
                // this exact check id — is unchanged, so no other person's or folder's check is
                // reachable here. income_source_id is deliberately NOT part of the lookup because
                // the locked row itself is the authoritative source identity for an update.
                $check = $checkId !== null
                    ? $folder->businessChecks()->where('co_maker_id', $activePerson?->id)->lockForUpdate()->findOrFail((int) $checkId)
                    : $folder->businessChecks()->make(['co_maker_id' => $activePerson?->id]);
                $created = ! $check->exists;

                if (! $created && (int) ($data['expected_revision'] ?? 0) !== $check->revision) {
                    throw new BusinessCheckConflictException;
                }

                // The selected business is create-time identity. Enforce immutability again under
                // the Business Check row lock so direct action callers and concurrent requests
                // cannot bypass the form request and repoint a saved snapshot.
                $savedIncomeSourceId = $check->income_source_id === null ? null : (int) $check->income_source_id;
                if (! $created && $referencedSourceId !== $savedIncomeSourceId) {
                    throw ValidationException::withMessages([
                        'income_source_id' => 'Business / Income Source cannot be changed after a Business Check is saved.',
                    ]);
                }

                $check->fill(Arr::only($data, self::FIELDS));
                $incomeSourceChanged = $created || $check->isDirty('income_source_id');
                if ($created) {
                    $check->ci_user_id = $actor->id;
                } else {
                    // Advanced on every successful update, so the token a form was rendered with
                    // can never be reused. A save that ends up rolling back (no-change, a failed
                    // upload) rolls this back with it, exactly like every other field here.
                    $check->revision++;
                }
                $check->updated_by = $actor->id;

                // Business Report / IncomeSource -> Business Check is the ONLY prefill direction.
                // The moment this check starts pointing at a business, it snapshots that exact
                // business's authoritative name, address and CI date — which is also why those
                // three inputs render read-only for a referenced business: whatever they submit is
                // ignored in favour of the source itself. This is PREFILL, not live
                // synchronization: once captured the snapshot never changes on its own again, even
                // if the Business Report is later renamed, re-addressed or deleted (see
                // BusinessReportBusinessCheckIndependenceTest). Nothing here ever writes back into
                // the IncomeSource or its Business Report.
                //
                // A manual Business Check (no referenced business) keeps exactly what the CI typed.
                if ($incomeSourceChanged && $referencedSourceId !== null) {
                    $source = $folder->incomeSources()->with('businessReport', 'template')->find($referencedSourceId);
                    // Per field: the business's own value wins where it HAS one. Business Name
                    // always resolves (the six templates with no Business Name input carry a
                    // derived default — see IncomeSourceTemplate::DEFAULT_BUSINESS_NAMES), and so
                    // does the mandatory CI Date. Main Business Address can genuinely be blank, in
                    // which case the address the CI typed here is kept and stored on this Business
                    // Check ALONE — the Business Report is never written to from here.
                    $check->business_name = $source?->businessCheckSnapshotName() ?: $check->business_name;
                    $check->location = $source?->businessReport?->main_business_address ?: $check->location;
                    $check->ci_date = $source?->businessReport?->start_date ?: $check->ci_date;
                }
                // Evaluated after the snapshot above so a value the source itself supplied still
                // counts as a real change for the audit/no-change rules below.
                $fieldsChanged = $check->isDirty(self::FIELDS);

                $check->save();

                // Explicitly creating/saving a Business Check for this business lifts any earlier
                // intentional deletion, so the work item legitimately returns to the Reports
                // workspace. Only a real save clears it — merely viewing Reports never does. The
                // Business Report's own suppression marker is deliberately not touched here.
                // Only meaningful for a check that references a business — a manual Business Check
                // has no income source whose suppression marker could need clearing.
                if ($check->income_source_id !== null) {
                    $folder->incomeSources()
                        ->whereKey($check->income_source_id)
                        ->whereNotNull('business_check_deleted_at')
                        ->update(['business_check_deleted_at' => null]);
                }

                $photosRemoved = 0;
                foreach ($data['removed_photo_ids'] ?? [] as $photoId) {
                    $photo = $check->photos()->find((int) $photoId);
                    if ($photo !== null) {
                        if ($photo->isCloud()) {
                            $retiredCloudAssets[] = ['public_id' => $photo->cloud_public_id, 'resource_type' => $photo->cloud_resource_type, 'delivery_type' => $photo->cloud_delivery_type];
                        } else {
                            array_push($retiredLocalPaths, $photo->path, $photo->thumbnail_path);
                        }
                        $photo->delete();
                        $photosRemoved++;
                    }
                }

                [$groupsPhotosUploaded, $groupsPhotosRemoved, $groupsChanged] = $this->syncPhotoGroups($folder, $check, $actor, $data['photo_groups'] ?? [], $storedUploads, $retiredCloudAssets, $retiredLocalPaths);
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
                        array_push($retiredLocalPaths, $check->map_screenshot_path, $check->map_screenshot_thumbnail_path);
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
                            array_push($retiredLocalPaths, $check->map_screenshot_path, $check->map_screenshot_thumbnail_path);
                        }
                    }
                    $stored = $this->mediaUploader->store($folder, $data['map_screenshot'], 'business/map-screenshots', 'map_screenshot', $check->co_maker_id === null, $activePerson);
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
                $this->progress->recalculate($folder);

                $cleanupTaskIds = $this->fileCleanup->stage(['local' => array_map(
                    fn (string $path): array => ['path' => $path, 'provider' => MediaReference::STORAGE_PROVIDER_LOCAL],
                    array_values(array_unique(array_filter($retiredLocalPaths))),
                )]);

                return $check->refresh();
            });

            DB::afterCommit(fn () => $this->fileCleanup->retire($cleanupTaskIds));
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
            $coMaker = $check->co_maker_id ? $folder->coMakers()->findOrFail($check->co_maker_id) : null;
            $stored = $this->mediaUploader->store($folder, $file, 'business/photos', 'photo', $check->co_maker_id === null, $coMaker);
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
    private function syncPhotoGroups(ClientFolder $folder, BusinessCheck $check, User $actor, array $groupsData, array &$storedUploads, array &$retiredCloudAssets, array &$retiredLocalPaths): array
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
                        $this->retirePhoto($photo, $retiredCloudAssets, $retiredLocalPaths);
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
                        $this->retirePhoto($photo, $retiredCloudAssets, $retiredLocalPaths);
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
                $claimed = BusinessCheckPhoto::query()
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

    private function retirePhoto(BusinessCheckPhoto $photo, array &$retiredCloudAssets, array &$retiredLocalPaths): void
    {
        if ($photo->isCloud()) {
            $retiredCloudAssets[] = ['public_id' => $photo->cloud_public_id, 'resource_type' => $photo->cloud_resource_type, 'delivery_type' => $photo->cloud_delivery_type];
        } else {
            array_push($retiredLocalPaths, $photo->path, $photo->thumbnail_path);
        }
        $photo->delete();
    }
}
