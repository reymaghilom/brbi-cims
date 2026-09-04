<?php

namespace App\Actions\ClientFolders;

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
 * BusinessReportBusinessCheckIndependenceTest). The IncomeSource itself is only ever removed if it
 * is left truly orphaned by this delete (see DeleteIncomeSourceIfOrphaned); the surviving Business
 * Check keeps using the same income_source_id it always has.
 */
class DeleteBusinessReport
{
    public function __construct(
        private readonly IncomeSourcesCompletionEvaluator $incomeCompletion,
        private readonly ResidenceBusinessCheckCompletionEvaluator $checkCompletion,
        private readonly ClientProgressService $progress,
        private readonly DeleteIncomeSourceIfOrphaned $deleteIfOrphaned,
    ) {}

    public function execute(User $actor, ClientFolder $folder, IncomeSource $source): void
    {
        DB::transaction(function () use ($actor, $folder, $source): void {
            $lockedSource = $this->exactSourceQuery($folder, $source)->lockForUpdate()->firstOrFail();
            $report = BusinessReport::query()->where('income_source_id', $lockedSource->id)->lockForUpdate()->firstOrFail();

            $reportId = $report->id;
            $businessName = $report->business_name;
            $coMakerId = $lockedSource->co_maker_id;

            $report->delete();
            $orphanRemoved = $this->deleteIfOrphaned->execute($lockedSource);

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
                    'income_source_orphan_removed' => $orphanRemoved,
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
