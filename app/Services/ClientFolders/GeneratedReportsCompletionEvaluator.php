<?php

namespace App\Services\ClientFolders;

use App\Enums\GenerationStatus;
use App\Enums\OfficialReportType;
use App\Models\ClientFolder;
use App\Models\CompletionRule;

class GeneratedReportsCompletionEvaluator
{
    public function evaluate(ClientFolder $folder): bool
    {
        $rule = CompletionRule::query()->where('code', 'required_reports')->where('is_active', true)->first();
        if ($rule === null) {
            return false;
        }

        $requiredScopes = [OfficialReportType::Cibi->value, OfficialReportType::ResidenceBusinessPhoto->value];
        $folder->incomeSources()->with('template:id,is_fallback')->get()->each(function ($source) use (&$requiredScopes): void {
            $type = $source->template->is_fallback ? OfficialReportType::GeneralIncomeSource : OfficialReportType::BusinessIncomeSource;
            $requiredScopes[] = $type->value.':'.$source->id;
        });

        $completedScopes = $folder->generatedReports()->where('status', GenerationStatus::Completed)->get(['report_type', 'income_source_id'])
            ->map(fn ($report) => $report->report_type.($report->income_source_id ? ':'.$report->income_source_id : ''))->unique();
        $complete = collect($requiredScopes)->every(fn ($scope) => $completedScopes->contains($scope));

        $folder->completionResults()->updateOrCreate(
            ['completion_rule_id' => $rule->id],
            ['is_satisfied' => $complete, 'score' => null, 'explanation_key' => $complete ? 'generated_reports.complete' : 'generated_reports.missing_applicable_outputs', 'evaluated_at' => now()],
        );

        return $complete;
    }
}
