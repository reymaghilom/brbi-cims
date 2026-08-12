<?php

namespace App\Services\ClientFolders;

use App\Enums\ActivityStatus;
use App\Models\ClientFolder;
use App\Models\CompletionRule;

class CiActivitiesCompletionEvaluator
{
    public function evaluate(ClientFolder $folder): bool
    {
        $rule = CompletionRule::query()->where('code', 'required_activities')->where('is_active', true)->first();
        if ($rule === null) {
            return false;
        }

        $required = $folder->activities()
            ->whereHas('definition', fn ($query) => $query->where('is_active', true)->where('is_required', true));
        $total = (clone $required)->count();
        $completed = (clone $required)->where('status', ActivityStatus::Completed)->count();
        $satisfied = $total > 0 && $completed === $total;

        $folder->completionResults()->updateOrCreate(
            ['completion_rule_id' => $rule->id],
            [
                'is_satisfied' => $satisfied,
                'score' => null,
                'explanation_key' => $satisfied ? 'required_activities.complete' : 'required_activities.pending',
                'evaluated_at' => now(),
            ],
        );

        return $satisfied;
    }
}
