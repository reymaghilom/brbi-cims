<?php

namespace App\Services\ClientFolders;

use App\Enums\ActivityStatus;
use App\Enums\GenerationStatus;
use App\Enums\RecordState;
use App\Models\AuditLog;
use App\Models\ClientCompletionResult;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Services\Progress\RequiredItemsProgressCalculator;

class ClientFolderOverview
{
    public function __construct(private readonly RequiredItemsProgressCalculator $progressCalculator) {}

    public function for(ClientFolder $folder, ?CoMaker $activePerson = null): array
    {
        $personId = $activePerson?->id;
        $folder = ClientFolder::query()
            ->with([
                'assignedInvestigator:id,full_name',
                'information:id,client_folder_id,completion_state,updated_at',
                'cibiReport' => fn ($query) => $query->where('co_maker_id', $personId)->select('id', 'client_folder_id', 'co_maker_id', 'state', 'updated_at'),
                'coMakers:id,client_folder_id,full_name,first_name,middle_name,last_name,relationship_to_applicant,contact_number,address',
            ])
            ->withCount([
                'incomeSources' => fn ($query) => $query->where('co_maker_id', $personId),
                'incomeSources as completed_income_sources_count' => fn ($query) => $query->where('co_maker_id', $personId)->where('state', RecordState::Complete),
                'residenceChecks' => fn ($query) => $query->where('co_maker_id', $personId),
                'businessChecks' => fn ($query) => $query->where('co_maker_id', $personId),
                'activities' => fn ($query) => $query->where('co_maker_id', $personId),
                'activities as completed_activities_count' => fn ($query) => $query->where('co_maker_id', $personId)->where('status', ActivityStatus::Completed),
                'activities as started_activities_count' => fn ($query) => $query->where('co_maker_id', $personId)->where('status', '!=', ActivityStatus::NotStarted),
                'activities as required_activities_count' => fn ($query) => $query->where('co_maker_id', $personId)->whereHas('definition', fn ($definition) => $definition->where('is_active', true)->where('is_required', true)),
                'activities as completed_required_activities_count' => fn ($query) => $query->where('co_maker_id', $personId)->where('status', ActivityStatus::Completed)->whereHas('definition', fn ($definition) => $definition->where('is_active', true)->where('is_required', true)),
                'activities as started_required_activities_count' => fn ($query) => $query->where('co_maker_id', $personId)->where('status', '!=', ActivityStatus::NotStarted)->whereHas('definition', fn ($definition) => $definition->where('is_active', true)->where('is_required', true)),
                'mediaReferences' => fn ($query) => $query->where('co_maker_id', $personId),
                'generatedReports' => fn ($query) => $query->where('co_maker_id', $personId),
                'generatedReports as completed_generated_reports_count' => fn ($query) => $query->where('co_maker_id', $personId)->where('status', GenerationStatus::Completed),
                // Dormant modules (no real UI yet) stay folder-level/unscoped — see decision #1.
                'driveReferences',
                'telegramMessages',
                'attachments',
            ])
            ->withMax([
                'incomeSources' => fn ($query) => $query->where('co_maker_id', $personId),
                'residenceChecks' => fn ($query) => $query->where('co_maker_id', $personId),
                'businessChecks' => fn ($query) => $query->where('co_maker_id', $personId),
                'activities' => fn ($query) => $query->where('co_maker_id', $personId),
                'mediaReferences' => fn ($query) => $query->where('co_maker_id', $personId),
                'generatedReports' => fn ($query) => $query->where('co_maker_id', $personId),
                'driveReferences', 'telegramMessages', 'attachments',
            ], 'updated_at')
            ->findOrFail($folder->id);

        $completionResults = ClientCompletionResult::query()
            ->where('client_folder_id', $folder->id)
            ->whereHas('rule', fn ($query) => $query->where('is_active', true)->where('is_required', true))
            ->join('completion_rules', 'completion_rules.id', '=', 'client_completion_results.completion_rule_id')
            ->orderBy('completion_rules.sort_order')
            ->get([
                'client_completion_results.is_satisfied',
                'completion_rules.label',
            ]);

        $calculated = $this->progressCalculator->calculate(
            $completionResults->mapWithKeys(fn ($result): array => [$result->label => $result->is_satisfied]),
        );

        $recentHistory = AuditLog::query()
            ->where('client_folder_id', $folder->id)
            ->with('user:id,full_name')
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'user_id', 'action', 'description', 'created_at']);

        return [
            'clientFolder' => $folder,
            'progress' => [
                'percentage' => (float) $folder->progress_percent,
                'completed' => count($calculated->completed),
                'total' => count($calculated->completed) + count($calculated->incomplete),
                'is_evaluated' => $completionResults->isNotEmpty(),
                'missing' => $calculated->incomplete,
            ],
            'modules' => $this->modules($folder),
            'recentHistory' => $recentHistory,
        ];
    }

    private function modules(ClientFolder $folder): array
    {
        return [
            $this->module('client-information', 'Client Information', 'user', $this->singleState($folder->information?->completion_state), $folder->information ? 'Client profile record available.' : 'No client information has been encoded.', $folder->information?->updated_at),
            $this->module('cibi-report', 'CI / BI Report', 'report', $this->singleState($folder->cibiReport?->state), $folder->cibiReport ? 'Official CI / BI report record available.' : 'No CI / BI report has been started.', $folder->cibiReport?->updated_at),
            $this->module('income-sources', 'Business / Income Sources', 'folder', $this->collectionState($folder->income_sources_count, $folder->completed_income_sources_count), null, $folder->income_sources_max_updated_at),
            $this->module('residence-business', 'Residence & Business Report', 'media', $this->residenceBusinessState($folder), $this->residenceBusinessDescription($folder), $this->latest($folder->residence_checks_max_updated_at, $folder->business_checks_max_updated_at)),
            $this->module('activities', 'CI Activities', 'activity', $this->activityState($folder), $this->activityDescription($folder), $folder->activities_max_updated_at),
            $this->module('media', 'Photos & Videos', 'media', $folder->media_references_count > 0 ? 'available' : 'not_started', $this->countDescription($folder->media_references_count, 'media item'), $folder->media_references_max_updated_at),
            $this->module('generated-reports', 'Generated Reports', 'report', $this->collectionState($folder->generated_reports_count, $folder->completed_generated_reports_count), $this->countDescription($folder->generated_reports_count, 'generated report'), $folder->generated_reports_max_updated_at),
            $this->module('attachments', 'Attachments / Documents', 'attachment', $folder->attachments_count > 0 ? 'available' : 'not_started', $this->countDescription($folder->attachments_count, 'document'), $folder->attachments_max_updated_at),
            $this->module('google-drive', 'Google Drive', 'drive', $folder->drive_references_count > 0 ? 'available' : 'not_configured', $this->countDescription($folder->drive_references_count, 'Drive reference'), $folder->drive_references_max_updated_at),
            $this->module('telegram-history', 'Telegram History', 'telegram', $folder->telegram_messages_count > 0 ? 'available' : 'not_started', $this->countDescription($folder->telegram_messages_count, 'message'), $folder->telegram_messages_max_updated_at),
        ];
    }

    private function module(string $key, string $title, string $icon, string $state, ?string $description, mixed $updatedAt): array
    {
        return compact('key', 'title', 'icon', 'state', 'description', 'updatedAt');
    }

    private function singleState(mixed $state): string
    {
        return $state?->value ?? 'not_started';
    }

    private function collectionState(int $total, int $completed): string
    {
        return match (true) {
            $total === 0 => 'not_started',
            $completed === $total => 'completed',
            default => 'in_progress',
        };
    }

    private function residenceBusinessState(ClientFolder $folder): string
    {
        return match (true) {
            $folder->residence_checks_count === 0 && $folder->business_checks_count === 0 => 'not_started',
            $folder->residence_checks_count === 0 => 'in_progress',
            default => 'completed',
        };
    }

    private function residenceBusinessDescription(ClientFolder $folder): string
    {
        $total = $folder->residence_checks_count + $folder->business_checks_count;

        return $total === 0
            ? 'No Residence or Business Checks have been saved.'
            : "{$folder->residence_checks_count} Residence, {$folder->business_checks_count} Business Check".($total === 1 ? '' : 's').' saved.';
    }

    private function latest(mixed ...$values): mixed
    {
        return collect($values)->filter()->sortDesc()->first();
    }

    private function activityState(ClientFolder $folder): string
    {
        return match (true) {
            $folder->required_activities_count === 0 || $folder->started_required_activities_count === 0 => 'not_started',
            $folder->completed_required_activities_count === $folder->required_activities_count => 'completed',
            default => 'in_progress',
        };
    }

    private function activityDescription(ClientFolder $folder): string
    {
        $pending = $folder->required_activities_count - $folder->completed_required_activities_count;

        return "{$folder->completed_required_activities_count} of {$folder->required_activities_count} required activities completed; {$pending} pending.";
    }

    private function countDescription(int $count, string $label): string
    {
        return $count === 0 ? "No {$label}s available." : "{$count} ".str($label)->plural($count).' available.';
    }
}
