<?php

namespace App\Actions\ClientFolders;

use App\Exceptions\BusinessReportDeleteConflictException;
use App\Models\AuditLog;
use App\Models\BusinessReport;
use App\Models\ClientFolder;
use App\Models\IncomeSource;
use App\Models\User;
use App\Services\ClientFolders\IncomeSourcesCompletionEvaluator;
use App\Services\ClientFolders\ResidenceBusinessCheckCompletionEvaluator;
use App\Services\Progress\ClientProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Permanently deletes a Business Report. There is no Recycle Bin entry and no restore for this —
 * the linked Business Check and IncomeSource are deliberately left untouched (see
 * BusinessReportBusinessCheckIndependenceTest). Only deleting the parent IncomeSource may remove
 * the business from active workflows; the surviving Business Check keeps using the same
 * income_source_id it always has.
 *
 * The deletion is also recorded on that exact IncomeSource (business_report_deleted_at) so the
 * centralized Reports workspace can tell "never had a Business Report" apart from "its Business
 * Report was intentionally deleted" and stop regenerating a Pending work item for the latter. A
 * saved Business Check is untouched by that marker and stays visible with its own status.
 */
class DeleteBusinessReport
{
    public function __construct(
        private readonly IncomeSourcesCompletionEvaluator $incomeCompletion,
        private readonly ResidenceBusinessCheckCompletionEvaluator $checkCompletion,
        private readonly ClientProgressService $progress,
    ) {}

    /**
     * $expectedRevision is the IncomeSource revision the delete screen was rendered with. When
     * given, it is compared under the exact IncomeSource row lock — after the report is confirmed
     * to still exist (so a double delete reads as "no longer available", not as a conflict) and
     * before anything is deleted — so a delete confirmed from a stale screen can never remove a
     * version another CI saved after that screen loaded.
     */
    public function execute(User $actor, ClientFolder $folder, IncomeSource $source, ?int $expectedRevision = null): void
    {
        DB::transaction(function () use ($actor, $folder, $source, $expectedRevision): void {
            $lockedSource = $this->exactSourceQuery($folder, $source)->lockForUpdate()->firstOrFail();
            $report = BusinessReport::query()->where('income_source_id', $lockedSource->id)->lockForUpdate()->firstOrFail();

            // Every HTTP delete path (single and bulk) requires the token, so null is only ever an
            // in-process caller, never a request.
            if ($expectedRevision !== null && $expectedRevision !== $lockedSource->revision) {
                throw BusinessReportDeleteConflictException::forReport();
            }

            $reportId = $report->id;
            $businessName = $report->business_name;
            $coMakerId = $lockedSource->co_maker_id;

            $report->delete();

            // A missing business_reports row cannot say WHY it is missing. Recording the deletion on
            // the exact IncomeSource is what stops the centralized Reports workspace re-synthesising
            // a "Create Report" work item for a business whose report was deliberately removed. It
            // is cleared again by SaveBusinessIncomeSource the moment a report is saved here.
            $lockedSource->forceFill(['business_report_deleted_at' => now()]);
            // Advancing the revision invalidates every Business Report edit form opened before this
            // delete: their expected_revision no longer matches, so SaveBusinessIncomeSource refuses
            // them instead of silently recreating the report from stale input. A form reopened after
            // the delete renders the new revision, so a deliberate recreate still works.
            //
            // Only for a genuinely saved report (revision > 1). A revision-1 row is the never-saved
            // shell convention that checkFirstCandidates() / isBlankLegacyPlaceholder() match on
            // exactly; advancing it would make that shell look saved. Every other `revision > 1`
            // consumer also requires the business_reports row, which is gone either way.
            if ($lockedSource->revision > 1) {
                $lockedSource->revision++;
            }
            $lockedSource->save();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'business_report.deleted',
                'module' => 'income_sources',
                'description' => 'A Business Report was permanently deleted.',
                'metadata' => [
                    'business_report_id' => $reportId,
                    'income_source_id' => $lockedSource->id,
                    'co_maker_id' => $coMakerId,
                    'business_name' => $businessName,
                    'income_source_orphan_removed' => false,
                    'business_report_suppressed' => true,
                    'revision' => $lockedSource->revision,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $this->incomeCompletion->evaluateFolder($folder);
            $this->checkCompletion->evaluate($folder, $coMakerId);
            $this->progress->recalculate($folder);
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
}
