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
use App\Services\Progress\ClientProgressService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RestoreBusinessPair
{
    public function __construct(
        private readonly IncomeSourcesCompletionEvaluator $incomeCompletion,
        private readonly ResidenceBusinessCheckCompletionEvaluator $checkCompletion,
        private readonly ClientProgressService $progress,
    ) {}

    public function execute(User $actor, ClientFolder $folder, IncomeSource $source): void
    {
        DB::transaction(function () use ($actor, $folder, $source): void {
            $lockedSource = $this->exactSourceQuery($folder, $source)->lockForUpdate()->firstOrFail();
            abort_unless($lockedSource->trashed(), 404);

            // Business Report and Business Check no longer support soft-delete/restore (see
            // DeleteBusinessReport/DeleteBusinessCheck) — either can only still exist here
            // untouched, never trashed, so this only ever restores the IncomeSource itself. This
            // action remains in place solely for non-dedicated-business IncomeSource types, which
            // never have either row to begin with.
            $report = BusinessReport::query()->where('income_source_id', $lockedSource->id)->first();
            $check = $this->exactCheckQuery($folder, $lockedSource)->first();
            $lockedSource->restore();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'income_source.restored',
                'module' => 'income_sources',
                'description' => 'A business report and its linked Business Check were restored from the Recycle Bin.',
                'metadata' => [
                    'income_source_id' => $lockedSource->id,
                    'business_report_id' => $report?->id,
                    'business_check_id' => $check?->id,
                    'co_maker_id' => $lockedSource->co_maker_id,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $this->incomeCompletion->evaluateFolder($folder);
            $this->checkCompletion->evaluate($folder, $lockedSource->co_maker_id);
            $this->progress->recalculate($folder);
        });
    }

    private function exactSourceQuery(ClientFolder $folder, IncomeSource $source): Builder
    {
        return IncomeSource::withTrashed()
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
