<?php

namespace App\Services\ClientFolders;

use App\Enums\PhotoSectionCategory;
use App\Enums\RecordState;
use App\Models\CompletionRule;
use App\Models\ResidenceBusinessReport;

class ResidenceBusinessReportCompletionEvaluator
{
    public function evaluate(ResidenceBusinessReport $report): bool
    {
        $rule = CompletionRule::query()->where('code', 'residence_business_report')->where('is_active', true)->first();
        if ($rule === null) {
            return false;
        }

        $sections = $report->sections()->withCount('mediaItems')->get();
        $requirementsMet = filled($report->report_date)
            && $sections->contains(fn ($section): bool => $section->category === PhotoSectionCategory::Residence)
            && $sections->every(function ($section): bool {
                $base = filled($section->heading_subject) && filled($section->location) && $section->media_items_count > 0;
                if ($section->category === PhotoSectionCategory::Business) {
                    return $base && filled($section->income_source_id) && filled($section->business_name);
                }

                return $base && ($section->subject_party === 'applicant' || filled($section->subject_name));
            });
        $complete = $requirementsMet && $report->state === RecordState::Complete;

        $report->update([
            'state' => $complete ? RecordState::Complete : RecordState::Draft,
            'completed_at' => $complete ? ($report->completed_at ?? now()) : null,
        ]);
        $report->clientFolder->completionResults()->updateOrCreate(
            ['completion_rule_id' => $rule->id],
            ['is_satisfied' => $complete, 'score' => null, 'explanation_key' => $complete ? 'residence_business_report.complete' : 'residence_business_report.missing_required_items', 'evaluated_at' => now()],
        );

        return $complete;
    }
}
