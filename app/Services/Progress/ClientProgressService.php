<?php

namespace App\Services\Progress;

use App\Enums\ClientFolderStatus;
use App\Models\ClientCompletionResult;
use App\Models\ClientFolder;
use App\Models\CompletionRule;
use App\Services\Progress\Contracts\ProgressResult;
use Illuminate\Support\Facades\DB;

class ClientProgressService
{
    public function __construct(
        private readonly RequiredItemsProgressCalculator $calculator,
        private readonly MandatoryInvestigationRequirements $mandatory,
    ) {}

    /**
     * The folder's progress from its mandatory investigation requirements (the Applicant's seven plus
     * four per existing Co-Maker; Asset Check is optional). Read-only.
     */
    public function calculate(ClientFolder $folder): ProgressResult
    {
        return $this->calculator->calculate($this->mandatory->evaluate($folder));
    }

    public function recalculate(ClientFolder $folder): void
    {
        $folderId = (int) $folder->getKey();

        if (DB::transactionLevel() > 0) {
            // Recalculation must see the activity mutation that requested it and every mutation
            // committed ahead of it. Register only after the surrounding transaction succeeds;
            // the small nested boundary also lets Laravel's test transaction manager execute the
            // callback after an application transaction commits, while preserving production's
            // outermost-commit semantics.
            DB::transaction(function () use ($folderId): void {
                DB::afterCommit(fn () => $this->recalculateSerialized($folderId));
            });

            return;
        }

        $this->recalculateSerialized($folderId);
    }

    private function recalculateSerialized(int $folderId): void
    {
        DB::transaction(function () use ($folderId): void {
            // All progress writers serialize on this exact row. The lock is acquired in a fresh
            // post-commit transaction before any requirement read, so the calculation cannot use
            // the stale repeatable-read snapshot of the activity mutation transaction.
            $folder = ClientFolder::query()->lockForUpdate()->findOrFail($folderId);

            // Per-module completion results are still kept for their existing readers; they no longer
            // decide the folder's percentage or status.
            $rules = CompletionRule::query()
                ->where('is_active', true)
                ->where('is_required', true)
                ->orderBy('sort_order')
                ->get(['id']);
            $existing = $folder->completionResults()->pluck('completion_rule_id')->all();
            foreach ($rules as $rule) {
                if (! in_array($rule->id, $existing)) {
                    ClientCompletionResult::create([
                        'client_folder_id' => $folder->id,
                        'completion_rule_id' => $rule->id,
                        'is_satisfied' => false,
                        'explanation_key' => 'pending_evaluation',
                        'evaluated_at' => now(),
                    ]);
                }
            }

            $progress = $this->calculate($folder);
            $folder->update([
                'progress_percent' => $progress->percentage,
                'status' => $progress->isComplete ? ClientFolderStatus::Completed : ClientFolderStatus::OnProgress,
                'completed_at' => $progress->isComplete ? ($folder->completed_at ?? now()) : null,
            ]);
        });
    }
}
