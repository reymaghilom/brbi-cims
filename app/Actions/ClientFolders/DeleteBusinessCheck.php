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

class DeleteBusinessCheck
{
    public function __construct(
        private readonly ResidenceBusinessCheckCompletionEvaluator $completion,
        private readonly IncomeSourcesCompletionEvaluator $incomeCompletion,
        private readonly ClientProgressService $progress,
    ) {}

    public function execute(User $actor, ClientFolder $folder, BusinessCheck $check): void
    {
        DB::transaction(function () use ($actor, $folder, $check): void {
            $lockedCheck = $this->exactCheckQuery($folder, $check)->lockForUpdate()->firstOrFail();
            $source = $this->exactSourceQuery($folder, $lockedCheck)->lockForUpdate()->firstOrFail();
            $report = BusinessReport::query()->where('income_source_id', $source->id)->lockForUpdate()->first();

            $checkId = $lockedCheck->id;
            $coMakerId = $lockedCheck->co_maker_id;
            $location = $lockedCheck->location;

            $report?->delete();
            $lockedCheck->delete();
            $source->delete();

            AuditLog::create([
                'user_id' => $actor->id,
                'client_folder_id' => $folder->id,
                'action' => 'business_check.deleted',
                'module' => 'residence_business_report',
                'description' => 'A Business Check and its linked Business Report were moved to the Recycle Bin.',
                'metadata' => [
                    'business_check_id' => $checkId,
                    'business_report_id' => $report?->id,
                    'income_source_id' => $source->id,
                    'co_maker_id' => $coMakerId,
                    'location' => $location,
                ],
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);

            $this->completion->evaluate($folder, $coMakerId);
            $this->incomeCompletion->evaluateFolder($folder);
            $this->progress->recalculate($folder);
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
