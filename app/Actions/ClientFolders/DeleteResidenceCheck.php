<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\ClientMediaUploader;
use App\Services\Media\PrivateMediaStorage;
use App\Services\Progress\ClientProgressService;
use Illuminate\Support\Facades\DB;

/**
 * Lock order across the whole Residence Check module, kept deliberately compatible so edit and
 * delete can never deadlock each other:
 *
 *  - EDIT (SaveResidenceCheck, existing check) and DELETE both take exactly one row lock inside
 *    their transaction — the residence_checks row itself — and nothing else. Whichever gets it
 *    first commits; the other waits and then acts on (or fails against) the authoritative result.
 *  - CREATE takes the client_folders / co_makers row lock and then only INSERTs a residence_checks
 *    row; it never waits on an existing residence_checks row lock (its existence lookup is an
 *    ordinary non-locking read), so it cannot close a cycle with the two paths above.
 *  - The client_folders lock used by progress recalculation is never held at the same time as the
 *    residence_checks lock: ClientProgressService defers to DB::afterCommit() whenever it is called
 *    inside a transaction, so that lock is only taken after this transaction has already committed
 *    and released its row lock.
 */
class DeleteResidenceCheck
{
    public function __construct(
        private readonly PrivateMediaStorage $storage,
        private readonly ClientMediaUploader $mediaUploader,
        private readonly ResidenceBusinessCheckCompletionEvaluator $completion,
        private readonly ClientProgressService $progress,
    ) {}

    public function execute(User $actor, ClientFolder $folder, ResidenceCheck $check): void
    {
        // Every Cloudinary asset this check owns (photos + its own Map Screenshot) is only ever
        // actually destroyed once the transaction below has committed successfully — never from
        // inside it, and never if it rolls back — same deferred-cleanup convention
        // SaveResidenceCheck/SaveBusinessCheck already use for a replaced/removed asset.
        // CloudinaryMediaStorage::destroy() is itself a safe no-op for a blank/already-gone public
        // id, so this stays idempotent even if the same delete is somehow retried.
        $retiredCloudAssets = [];

        DB::transaction(function () use ($actor, $folder, $check, &$retiredCloudAssets): void {
            // The route-bound instance is a snapshot from before this request's transaction, so it
            // is never treated as authoritative here: the row is re-read and locked first, exactly
            // like SaveResidenceCheck's edit path does, and everything below reads from that locked
            // row. Without this, two overlapping deletes both worked off their own stale copies and
            // each wrote a "deleted" audit event and retired the same Cloudinary assets, and a
            // delete overlapping an edit could snapshot the photo list before the edit's new photo
            // was committed and leave that asset orphaned.
            //
            // The scope is the same exact-person scope the rest of the module uses — this folder,
            // this person (Applicant = co_maker_id NULL, or one exact Co-Maker), this id — so a
            // locked lookup can never reach another person's or another folder's check.
            //
            // firstOrFail() when the row is already gone: a second/concurrent delete gets the same
            // 404 the route binding itself would have produced had it resolved a moment later, so
            // it reports no success, writes no audit event and cleans up no storage twice.
            $check = $folder->residenceChecks()
                ->where('co_maker_id', $check->co_maker_id)
                ->whereKey($check->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $checkId = $check->id;
            $coMakerId = $check->co_maker_id;
            $location = $check->location;

            $photos = $check->photos()->get(['path', 'thumbnail_path', 'cloud_public_id', 'cloud_resource_type', 'cloud_delivery_type']);
            $localPaths = $photos->flatMap(fn ($photo) => [$photo->path, $photo->thumbnail_path])->all();
            foreach ($photos as $photo) {
                if ($photo->isCloud()) {
                    $retiredCloudAssets[] = ['public_id' => $photo->cloud_public_id, 'resource_type' => $photo->cloud_resource_type, 'delivery_type' => $photo->cloud_delivery_type];
                }
            }

            if ($check->hasMapScreenshot()) {
                $localPaths[] = $check->map_screenshot_path;
                $localPaths[] = $check->map_screenshot_thumbnail_path;
                if ($check->hasCloudMapScreenshot()) {
                    $retiredCloudAssets[] = ['public_id' => $check->map_screenshot_cloud_public_id, 'resource_type' => $check->map_screenshot_cloud_resource_type, 'delivery_type' => $check->map_screenshot_cloud_delivery_type];
                }
            }

            $this->storage->deleteStoredFiles($localPaths);
            $check->delete();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'residence_check.deleted',
                'module' => 'residence_business_report',
                'description' => 'A Residence Check was deleted.',
                'metadata' => ['residence_check_id' => $checkId, 'co_maker_id' => $coMakerId, 'location' => $location],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $this->completion->evaluate($folder, $coMakerId);
            $this->progress->recalculate($folder);
        });

        DB::afterCommit(function () use ($retiredCloudAssets): void {
            foreach ($retiredCloudAssets as $asset) {
                $this->mediaUploader->retireCloudAsset($asset['public_id'], $asset['resource_type'], $asset['delivery_type']);
            }
        });
    }
}
