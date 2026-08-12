<?php

namespace App\Services\Progress;

use App\Enums\ClientFolderStatus;
use App\Models\ClientCompletionResult;
use App\Models\ClientFolder;
use App\Models\CompletionRule;

class ClientProgressService
{
    public function __construct(private readonly RequiredItemsProgressCalculator $calculator) {}

    public function recalculate(ClientFolder $folder): void
    {
        $rules = CompletionRule::query()
            ->where('is_active', true)
            ->where('is_required', true)
            ->orderBy('sort_order')
            ->get(['id', 'label']);

        $existing = $folder->completionResults()->get()->keyBy('completion_rule_id');
        $requirements = [];

        foreach ($rules as $rule) {
            $result = $existing->get($rule->id) ?? ClientCompletionResult::create([
                'client_folder_id' => $folder->id,
                'completion_rule_id' => $rule->id,
                'is_satisfied' => false,
                'explanation_key' => 'pending_evaluation',
                'evaluated_at' => now(),
            ]);
            $requirements[$rule->label] = $result->is_satisfied;
        }

        $progress = $this->calculator->calculate($requirements);
        $folder->update([
            'progress_percent' => $progress->percentage,
            'status' => $progress->isComplete ? ClientFolderStatus::Completed : ClientFolderStatus::OnProgress,
            'completed_at' => $progress->isComplete ? ($folder->completed_at ?? now()) : null,
        ]);
    }
}
