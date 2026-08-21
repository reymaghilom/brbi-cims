<?php

namespace App\Services\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CompletionRule;

class ResidenceBusinessCheckCompletionEvaluator
{
    /** Re-evaluates the `residence_business_report` completion rule from the active person's saved Residence/Business Checks. */
    public function evaluate(ClientFolder $folder, ?int $coMakerId): bool
    {
        $rule = CompletionRule::query()->where('code', 'residence_business_report')->where('is_active', true)->first();
        if ($rule === null) {
            return false;
        }

        $residenceChecks = $folder->residenceChecks()->where('co_maker_id', $coMakerId)->withCount('photos')->get();
        $businessChecks = $folder->businessChecks()->where('co_maker_id', $coMakerId)->withCount('photos')->get();

        $complete = $residenceChecks->isNotEmpty()
            && $residenceChecks->every(fn ($check): bool => filled($check->ci_date) && filled($check->location) && $check->photos_count > 0)
            && $businessChecks->every(fn ($check): bool => filled($check->ci_date) && filled($check->location) && $check->photos_count > 0);

        $folder->completionResults()->updateOrCreate(
            ['completion_rule_id' => $rule->id],
            ['is_satisfied' => $complete, 'score' => null, 'explanation_key' => $complete ? 'residence_business_report.complete' : 'residence_business_report.missing_required_items', 'evaluated_at' => now()],
        );

        return $complete;
    }
}
