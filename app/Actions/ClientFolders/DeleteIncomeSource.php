<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\User;
use App\Services\ClientFolders\IncomeSourcesCompletionEvaluator;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Media\BusinessCheckMediaCleanup;
use App\Services\Media\ClientMediaUploader;
use App\Services\Progress\ClientProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DeleteIncomeSource
{
    public function __construct(
        private readonly IncomeSourcesCompletionEvaluator $completion,
        private readonly ResidenceBusinessCheckCompletionEvaluator $checkCompletion,
        private readonly ClientProgressService $progress,
        private readonly BusinessCheckMediaCleanup $mediaCleanup,
        private readonly ClientMediaUploader $mediaUploader,
    ) {}

    public function execute(User $actor, ClientFolder $folder, IncomeSource $source): void
    {
        // Same deferred-cleanup convention as DeleteBusinessCheck: the linked check's Cloudinary
        // assets are only destroyed once the transaction below has committed.
        $retiredCloudAssets = [];

        DB::transaction(function () use ($actor, $folder, $source, &$retiredCloudAssets): void {
            $lockedSource = $this->exactSourceQuery($folder, $source)->lockForUpdate()->firstOrFail();
            $sourceId = $lockedSource->id;
            $templateType = $lockedSource->template_type;
            $coMakerId = $lockedSource->co_maker_id;
            $displayName = $lockedSource->displayName();

            $report = BusinessReport::query()->where('income_source_id', $sourceId)->lockForUpdate()->first();
            $check = $this->exactCheckQuery($folder, $lockedSource)->lockForUpdate()->first();

            // BusinessReport and BusinessCheck have no SoftDeletes, so both are already permanent
            // here; the IncomeSource is force-deleted alongside them because there is no Recycle
            // Bin to reach a soft-deleted row from any more. Deleting the two children first keeps
            // the cascadeOnDelete FKs from doing it implicitly.
            //
            // The check's photo/group ROWS cascade away with it, but its local files and Cloudinary
            // originals do not — deleting the row alone would strand them. This reuses the exact
            // cleanup DeleteBusinessCheck performs, without invoking that action: doing so would
            // also write a business_check_deleted_at suppression marker onto an IncomeSource that
            // is about to be deleted, re-run the same evaluators, and emit a second, misleading
            // business_check.deleted lifecycle event beside this action's income_source.deleted.
            $report?->delete();
            if ($check !== null) {
                $retiredCloudAssets = $this->mediaCleanup->purgeLocalFilesAndCollectCloudAssets($check);
                $check->delete();
            }
            $lockedSource->forceDelete();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'income_source.deleted',
                'module' => 'income_sources',
                'description' => 'A business and its linked Business Report and Business Check were permanently deleted.',
                'metadata' => [
                    'income_source_id' => $sourceId,
                    'business_report_id' => $report?->id,
                    'business_check_id' => $check?->id,
                    'co_maker_id' => $coMakerId,
                    'template_type' => $templateType,
                    'display_name' => $displayName,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $this->completion->evaluateFolder($folder);
            $this->checkCompletion->evaluate($folder, $coMakerId);
            $this->progress->recalculate($folder);
        });

        DB::afterCommit(function () use ($retiredCloudAssets): void {
            foreach ($retiredCloudAssets as $asset) {
                $this->mediaUploader->retireCloudAsset($asset['public_id'], $asset['resource_type'], $asset['delivery_type']);
            }
        });
    }

    private function exactSourceQuery(ClientFolder $folder, IncomeSource $source): Builder
    {
        return IncomeSource::query()
            ->whereKey($source->id)
            ->where('client_folder_id', $folder->id)
            ->when(
                $source->co_maker_id === null,
                fn (Builder $query) => $query->whereNull('co_maker_id'),
                fn (Builder $query) => $query->where('co_maker_id', $source->co_maker_id),
            );
    }

    private function exactCheckQuery(ClientFolder $folder, IncomeSource $source): Builder
    {
        return BusinessCheck::query()
            ->where('client_folder_id', $folder->id)
            ->where('income_source_id', $source->id)
            ->when(
                $source->co_maker_id === null,
                fn (Builder $query) => $query->whereNull('co_maker_id'),
                fn (Builder $query) => $query->where('co_maker_id', $source->co_maker_id),
            );
    }
}
