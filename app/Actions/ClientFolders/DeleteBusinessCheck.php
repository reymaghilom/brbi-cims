<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\User;
use App\Services\ClientFolders\IncomeSourcesCompletionEvaluator;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\ClientMediaUploader;
use App\Services\Media\PrivateMediaStorage;
use App\Services\Progress\ClientProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Permanently deletes a Business Check. There is no Recycle Bin entry and no restore for this —
 * the linked Business Report and IncomeSource are deliberately left untouched (see
 * BusinessReportBusinessCheckIndependenceTest): deleting one is never allowed to affect the other.
 * Only deleting the parent IncomeSource may remove the business from active workflows.
 *
 * The deletion is also recorded on that exact IncomeSource (business_check_deleted_at) so the
 * centralized Reports workspace can tell "never had a Business Check" apart from "its Business Check
 * was intentionally deleted" and stop regenerating a Pending work item for the latter. This marker
 * is the Business Check's alone — the Business Report's own state and visibility are untouched.
 */
class DeleteBusinessCheck
{
    public function __construct(
        private readonly ResidenceBusinessCheckCompletionEvaluator $completion,
        private readonly IncomeSourcesCompletionEvaluator $incomeCompletion,
        private readonly ClientProgressService $progress,
        private readonly PrivateMediaStorage $storage,
        private readonly ClientMediaUploader $mediaUploader,
    ) {}

    public function execute(User $actor, ClientFolder $folder, BusinessCheck $check): void
    {
        // Every Cloudinary asset this check owns (business/competitor photos + its own Map
        // Screenshot) is only ever actually destroyed once the transaction below has committed —
        // same deferred-cleanup convention as DeleteResidenceCheck/SaveBusinessCheck.
        $retiredCloudAssets = [];

        DB::transaction(function () use ($actor, $folder, $check, &$retiredCloudAssets): void {
            $lockedCheck = $this->exactCheckQuery($folder, $check)->lockForUpdate()->firstOrFail();
            $source = $this->exactSourceQuery($folder, $lockedCheck)->lockForUpdate()->firstOrFail();

            $checkId = $lockedCheck->id;
            $coMakerId = $lockedCheck->co_maker_id;
            $location = $lockedCheck->location;
            $businessName = $lockedCheck->business_name;

            $photos = $lockedCheck->photos()->get(['path', 'thumbnail_path', 'cloud_public_id', 'cloud_resource_type', 'cloud_delivery_type']);
            $localPaths = $photos->flatMap(fn ($photo) => [$photo->path, $photo->thumbnail_path])->all();
            foreach ($photos as $photo) {
                if ($photo->isCloud()) {
                    $retiredCloudAssets[] = ['public_id' => $photo->cloud_public_id, 'resource_type' => $photo->cloud_resource_type, 'delivery_type' => $photo->cloud_delivery_type];
                }
            }
            if ($lockedCheck->hasMapScreenshot()) {
                $localPaths[] = $lockedCheck->map_screenshot_path;
                $localPaths[] = $lockedCheck->map_screenshot_thumbnail_path;
                if ($lockedCheck->hasCloudMapScreenshot()) {
                    $retiredCloudAssets[] = ['public_id' => $lockedCheck->map_screenshot_cloud_public_id, 'resource_type' => $lockedCheck->map_screenshot_cloud_resource_type, 'delivery_type' => $lockedCheck->map_screenshot_cloud_delivery_type];
                }
            }
            $this->storage->deleteStoredFiles($localPaths);

            $lockedCheck->delete();

            // A missing business_checks row cannot say WHY it is missing. Recording the deletion on
            // the exact IncomeSource is what stops the centralized Reports workspace re-synthesising
            // a "Create Report" Business Check work item for a business whose check was deliberately
            // removed. It is its own marker, never the Business Report's: the Business Report keeps
            // whatever state it already had. SaveBusinessCheck clears it on the next real save.
            $source->forceFill(['business_check_deleted_at' => now()])->save();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'business_check.deleted',
                'module' => 'residence_business_report',
                'description' => 'A Business Check was permanently deleted.',
                'metadata' => [
                    'business_check_id' => $checkId,
                    'income_source_id' => $source->id,
                    'co_maker_id' => $coMakerId,
                    'location' => $location,
                    'business_name' => $businessName,
                    'income_source_orphan_removed' => false,
                    'business_check_suppressed' => true,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $this->completion->evaluate($folder, $coMakerId);
            $this->incomeCompletion->evaluateFolder($folder);
            $this->progress->recalculate($folder);
        });

        DB::afterCommit(function () use ($retiredCloudAssets): void {
            foreach ($retiredCloudAssets as $asset) {
                $this->mediaUploader->retireCloudAsset($asset['public_id'], $asset['resource_type'], $asset['delivery_type']);
            }
        });
    }

    private function exactCheckQuery(ClientFolder $folder, BusinessCheck $check): Builder
    {
        return BusinessCheck::query()
            ->whereKey($check->id)
            ->where('client_folder_id', $folder->id)
            ->where('income_source_id', $check->income_source_id)
            ->when(
                $check->co_maker_id === null,
                fn (Builder $query) => $query->whereNull('co_maker_id'),
                fn (Builder $query) => $query->where('co_maker_id', $check->co_maker_id),
            );
    }

    private function exactSourceQuery(ClientFolder $folder, BusinessCheck $check): Builder
    {
        return IncomeSource::query()
            ->whereKey($check->income_source_id)
            ->where('client_folder_id', $folder->id)
            ->when(
                $check->co_maker_id === null,
                fn (Builder $query) => $query->whereNull('co_maker_id'),
                fn (Builder $query) => $query->where('co_maker_id', $check->co_maker_id),
            );
    }
}
