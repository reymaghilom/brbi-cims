<?php

namespace App\Services\ClientFolders;

use App\Enums\RecordState;
use App\Models\ClientFolder;
use App\Models\CompletionRule;
use App\Models\IncomeSource;

class IncomeSourcesCompletionEvaluator
{
    public function evaluateSource(IncomeSource $source): bool
    {
        $requirementsMet = $source->template->is_fallback
            ? $this->fallbackComplete($source)
            : $this->businessComplete($source);
        $complete = $requirementsMet && $source->state === RecordState::Complete;

        $source->update([
            'state' => $complete ? RecordState::Complete : RecordState::Draft,
            'completed_at' => $complete ? ($source->completed_at ?? now()) : null,
        ]);

        return $complete;
    }

    public function evaluateFolder(ClientFolder $folder): bool
    {
        $rule = CompletionRule::query()->where('code', 'income_sources')->where('is_active', true)->first();
        if ($rule === null) {
            return false;
        }

        $total = $folder->incomeSources()->count();
        $complete = $total > 0 && $folder->incomeSources()->where('state', '!=', RecordState::Complete)->doesntExist();
        $folder->completionResults()->updateOrCreate(
            ['completion_rule_id' => $rule->id],
            ['is_satisfied' => $complete, 'score' => null, 'explanation_key' => $complete ? 'income_sources.complete' : 'income_sources.pending', 'evaluated_at' => now()],
        );

        return $complete;
    }

    private function fallbackComplete(IncomeSource $source): bool
    {
        $report = $source->generalReport;

        return filled($source->source_name) && filled($source->applicant_name_snapshot)
            && filled($source->branch_name) && filled($source->amount_applied) && filled($source->account_officer_name)
            && $report !== null && $report->declaredItems()->exists()
            && $report->declaredItems()->where('contribution_rank', 1)->exists();
    }

    private function businessComplete(IncomeSource $source): bool
    {
        $report = $source->businessReport;
        if ($report === null || ! filled($source->source_name) || ! filled($report->business_name) || ! filled($report->report_category)) {
            return false;
        }

        if ($source->template->businessReportSchema() !== []) {
            return collect($report->template_data ?? [])->flatten()->contains(fn ($value): bool => filled($value));
        }

        if (! filled($report->main_business_address) || ! filled($report->registered_owner)) {
            return false;
        }

        $tags = $source->template->compatibility_tags ?? [];
        if (in_array('properties', $tags, true) && ! $report->properties()->exists()) {
            return false;
        }
        if (in_array('branches', $tags, true) && ! $report->branches()->exists()) {
            return false;
        }
        if (in_array('products', $tags, true) && ! $report->products()->exists()) {
            return false;
        }

        return true;
    }
}
