<?php

namespace App\Actions\ClientFolders;

use App\Models\AuditLog;
use App\Models\BusinessCheck;
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
 *
 * Lock order across the Business Check module, kept compatible so no path can deadlock another:
 *
 *  - DELETE: the business_checks row first, then (only for a linked check) that exact
 *    income_sources row for the suppression marker.
 *  - EDIT (SaveBusinessCheck on an existing check): the business_checks row first, then — only when
 *    repointing to a different business — that target income_sources row. Same order as DELETE,
 *    so it can never hold an income_sources lock while waiting for a check row.
 *  - CREATE: the income_sources row first, then it only INSERTs a business_checks row. Its
 *    duplicate lookup is an ordinary non-locking read, so it never waits on an existing
 *    business_checks row lock and cannot close a cycle with the two paths above.
 *  - The client_folders lock used by progress recalculation is never held alongside either:
 *    ClientProgressService defers to DB::afterCommit() when called inside a transaction, so it runs
 *    only after this transaction has committed and released its locks.
 */
class DeleteBusinessCheck
{
    public function __construct(
        private readonly ResidenceBusinessCheckCompletionEvaluator $completion,
        private readonly IncomeSourcesCompletionEvaluator $incomeCompletion,
        private readonly ClientProgressService $progress,
        private readonly BusinessCheckMediaCleanup $mediaCleanup,
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
            // A standalone Business Check (income_source_id = null — a fully supported mode, see
            // SaveBusinessCheck and BusinessCheckIndependentArchitectureTest) references no
            // business at all, so there is no row to lock and no suppression marker to write. Only
            // a linked check resolves its exact IncomeSource, and that resolution stays strict:
            // firstOrFail() still rejects a forged/cross-person income_source_id exactly as before.
            $source = $lockedCheck->income_source_id === null
                ? null
                : $this->exactSourceQuery($folder, $lockedCheck)->lockForUpdate()->firstOrFail();

            $checkId = $lockedCheck->id;
            $coMakerId = $lockedCheck->co_maker_id;
            $location = $lockedCheck->location;
            $businessName = $lockedCheck->business_name;

            $retiredCloudAssets = $this->mediaCleanup->purgeLocalFilesAndCollectCloudAssets($lockedCheck);

            $lockedCheck->delete();

            // A missing business_checks row cannot say WHY it is missing. Recording the deletion on
            // the exact IncomeSource is what stops the centralized Reports workspace re-synthesising
            // a "Create Report" Business Check work item for a business whose check was deliberately
            // removed. It is its own marker, never the Business Report's: the Business Report keeps
            // whatever state it already had. SaveBusinessCheck clears it on the next real save.
            // A standalone check has no IncomeSource whose Business Check work item could ever be
            // re-synthesised, so there is nothing to suppress and nothing is written here.
            $source?->forceFill(['business_check_deleted_at' => now()])->save();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'business_check.deleted',
                'module' => 'residence_business_report',
                'description' => 'A Business Check was permanently deleted.',
                'metadata' => [
                    'business_check_id' => $checkId,
                    'income_source_id' => $source?->id,
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

    /**
     * Delete identity is the exact Business Check ROW — its id, inside this exact folder and this
     * exact person's scope. Never its business name, location, CI date or referenced business, so
     * two same-named checks can never be confused for one another.
     *
     * income_source_id is deliberately NOT part of this lookup even though the route-bound instance
     * carries one: it is a MUTABLE field (SaveBusinessCheck lets a CI repoint a check at a different
     * business). Pinning the stale instance's value here meant that when an edit won the race and
     * repointed the check, the delete could no longer resolve the row it was authorized to delete
     * and failed as "not found" instead of removing the authoritative record. The id plus the
     * folder and person scope already identify the row exactly, so nothing is lost by dropping it.
     */
    private function exactCheckQuery(ClientFolder $folder, BusinessCheck $check): Builder
    {
        return BusinessCheck::query()
            ->whereKey($check->id)
            ->where('client_folder_id', $folder->id)
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
