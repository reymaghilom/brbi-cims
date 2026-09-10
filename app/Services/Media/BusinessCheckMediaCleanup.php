<?php

namespace App\Services\Media;

use App\Models\BusinessCheck;

/**
 * Single definition of "which media does a Business Check own, and how is it retired", shared by
 * every permanent Business Check removal path: the dedicated DeleteBusinessCheck and the whole
 * business DeleteIncomeSource, which removes the same check as part of its parent IncomeSource.
 *
 * Ownership is resolved purely through the check's own relations/columns (its photos across every
 * photo group and category, plus its own Map Screenshot) — never by file name or business name — so
 * another business's, person's or folder's media can never be swept up. The photo/group ROWS
 * themselves are removed by the existing cascadeOnDelete FKs when the check row goes; what those
 * cascades cannot do is delete the local files or the Cloudinary originals, which is exactly what
 * this covers.
 *
 * Cloudinary assets are deliberately NOT destroyed here: they are returned so the caller can retire
 * them only after its own transaction commits (DB::afterCommit), preserving the existing convention
 * that a rolled-back delete — including one rolled back by an outer batch transaction — never
 * destroys an irreversible external asset.
 */
class BusinessCheckMediaCleanup
{
    public function __construct(private readonly PrivateMediaStorage $storage) {}

    /**
     * Deletes every local file the check owns and returns its Cloudinary assets, each shaped as
     * ['public_id' => ?string, 'resource_type' => ?string, 'delivery_type' => ?string], for the
     * caller to retire after commit.
     */
    public function purgeLocalFilesAndCollectCloudAssets(BusinessCheck $check): array
    {
        $cloudAssets = [];

        $photos = $check->photos()->get(['path', 'thumbnail_path', 'cloud_public_id', 'cloud_resource_type', 'cloud_delivery_type']);
        $localPaths = $photos->flatMap(fn ($photo) => [$photo->path, $photo->thumbnail_path])->all();
        foreach ($photos as $photo) {
            if ($photo->isCloud()) {
                $cloudAssets[] = ['public_id' => $photo->cloud_public_id, 'resource_type' => $photo->cloud_resource_type, 'delivery_type' => $photo->cloud_delivery_type];
            }
        }

        if ($check->hasMapScreenshot()) {
            $localPaths[] = $check->map_screenshot_path;
            $localPaths[] = $check->map_screenshot_thumbnail_path;
            if ($check->hasCloudMapScreenshot()) {
                $cloudAssets[] = ['public_id' => $check->map_screenshot_cloud_public_id, 'resource_type' => $check->map_screenshot_cloud_resource_type, 'delivery_type' => $check->map_screenshot_cloud_delivery_type];
            }
        }

        $this->storage->deleteStoredFiles($localPaths);

        return $cloudAssets;
    }
}
