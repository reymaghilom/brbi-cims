<?php

namespace App\Services\ClientFolders;

use App\Enums\RecordState;
use App\Models\CibiReport;
use App\Models\CompletionRule;

class CibiReportCompletionEvaluator
{
    public function evaluate(CibiReport $report): bool
    {
        $rule = CompletionRule::query()->where('code', 'cibi_report')->where('is_active', true)->first();
        if ($rule === null) {
            return false;
        }

        $condition = $rule->source_condition ?? [];
        $fields = $condition['required_report_fields'] ?? [
            'start_date', 'submitted_date', 'party_type', 'branch_name', 'account_officer_name',
            'ci_risk_level', 'personal_snapshot.age', 'personal_snapshot.present_address',
            'personal_snapshot.residence_status', 'personal_snapshot.home_condition',
            'personal_snapshot.number_of_storeys', 'personal_snapshot.material_cost_level',
            'personal_snapshot.living_condition', 'personal_snapshot.parents_address',
            'personal_snapshot.civil_status', 'personal_snapshot.reputation',
            'personal_snapshot.barangay_findings', 'personal_snapshot.lifestyle',
        ];
        // Ignore legacy completion-rule entries so spouse details remain optional
        // for reports created before and after this presentation adjustment.
        $fields = array_values(array_diff($fields, [
            'personal_snapshot.spouse_age',
            'personal_snapshot.spouse_name',
        ]));
        $childCounts = $condition['required_child_counts'] ?? [];

        $reportData = $report->toArray();
        $requirementsMet = collect($fields)->every(fn (string $field): bool => filled(data_get($reportData, $field)));
        foreach ($childCounts as $relation => $minimum) {
            $satisfied = $minimum <= $report->{$relation}()->count();
            if ($relation === 'legalFindings' && ! $satisfied) {
                $personal = $report->personal_snapshot ?? [];
                $satisfied = collect(['barangay_findings', 'court_background_status'])
                    ->contains(fn (string $key): bool => filled($personal[$key] ?? null) && $personal[$key] !== 'N/A');
            }
            $requirementsMet = $requirementsMet && $satisfied;
        }

        $complete = $requirementsMet && $report->state === RecordState::Complete;
        $report->update([
            'state' => $complete ? RecordState::Complete : RecordState::Draft,
            'completed_at' => $complete ? ($report->completed_at ?? now()) : null,
        ]);
        $report->clientFolder->completionResults()->updateOrCreate(
            ['completion_rule_id' => $rule->id],
            [
                'is_satisfied' => $complete,
                'score' => null,
                'explanation_key' => $complete ? 'cibi_report.complete' : 'cibi_report.missing_required_items',
                'evaluated_at' => now(),
            ],
        );

        return $complete;
    }
}
