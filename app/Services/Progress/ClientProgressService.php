<?php

namespace App\Services\Progress;

use App\Enums\ClientFolderStatus;
use App\Models\ClientCompletionResult;
use App\Models\ClientFolder;
use App\Models\CompletionRule;
use App\Services\Progress\Contracts\ProgressResult;

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
    }
}
