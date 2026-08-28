<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\ResidenceCheck;
use App\Models\User;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\ClientMediaUploader;
use App\Services\Media\PrivateMediaStorage;
use Illuminate\Support\Facades\DB;

class DeleteResidenceCheck
{
    public function __construct(
        private readonly PrivateMediaStorage $storage,
        private readonly ClientMediaUploader $mediaUploader,
        private readonly ResidenceBusinessCheckCompletionEvaluator $completion,
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
        });

        DB::afterCommit(function () use ($retiredCloudAssets): void {
            foreach ($retiredCloudAssets as $asset) {
                $this->mediaUploader->retireCloudAsset($asset['public_id'], $asset['resource_type'], $asset['delivery_type']);
            }
        });
    }
}
