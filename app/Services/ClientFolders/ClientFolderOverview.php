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
            'recentPersonActivity' => $this->recentPersonActivity($folder, $activePerson),
        ];
    }

    /**
     * Co-Maker lifecycle events affect one specific person but are logged under the shared
     * 'client_folders' module (they're participant-management, not that person's own record
     * activity). They get their own person-scoping rule below rather than the generic
     * folder-level bypass.
     */
    private const CO_MAKER_LIFECYCLE_ACTIONS = ['co_maker.added', 'co_maker.updated', 'co_maker.removed'];

    private const MEDIA_ACTIONS = ['media.uploaded', 'media.removed'];

    /**
     * Meaningful, person-scoped activity for the Recent Activity side panel. Folder-level
     * lifecycle events (module 'client_folders') are always included, since they belong to the
     * shared folder rather than any one person. Everything else must self-report its own
     * co_maker_id in metadata (written at the same time as the event) — an event missing that
     * key is never guessed into a person bucket and is simply excluded.
     */
    private function recentPersonActivity(ClientFolder $folder, ?CoMaker $activePerson): \Illuminate\Support\Collection
    {
        $personId = $activePerson?->id;
        $personContextLabel = $activePerson ? 'Co-Maker: '.mb_strtoupper($activePerson->full_name) : 'Applicant';

        // 'person' => true means this action belongs to one specific record owner (Applicant or
        // a Co-Maker) — the row shows the "Applicant" / "Co-Maker: NAME" context line for it.
        // Folder-level and Co-Maker-lifecycle actions never get that line: the former belongs to
        // no one person, the latter already names the affected Co-Maker via its own 'detail'.
        $labels = [
            'client_folder.created' => ['label' => 'Folder created', 'icon' => 'folder'],
            'client_folder.renamed' => ['label' => 'Folder renamed', 'icon' => 'edit'],
            'client_folder.recycled' => ['label' => 'Moved to Recycle Bin', 'icon' => 'trash'],
            'client_folder.restored' => ['label' => 'Restored from Recycle Bin', 'icon' => 'check-circle'],
            'co_maker.added' => ['label' => 'Co-Maker added', 'icon' => 'users', 'detail' => 'full_name'],
            'co_maker.updated' => ['label' => 'Co-Maker updated', 'icon' => 'users', 'detail' => 'full_name'],
            'co_maker.removed' => ['label' => 'Co-Maker removed', 'icon' => 'users', 'detail' => 'full_name'],
            'cibi_report.created' => ['label' => 'CI/BI created', 'icon' => 'report', 'person' => true],
            'cibi_report.updated' => ['label' => 'CI/BI updated', 'icon' => 'report', 'person' => true],
            'cibi_report.signatory_reassigned' => ['label' => 'CI/BI Signatory reassigned', 'icon' => 'report', 'person' => true],
            'income_source.created' => ['label' => 'Business added', 'icon' => 'folder', 'detail' => 'display_name', 'person' => true],
            'income_source.deleted' => ['label' => 'Business removed', 'icon' => 'trash', 'detail' => 'display_name', 'person' => true],
            'business_report.updated' => ['label' => 'Business updated', 'icon' => 'folder', 'detail' => 'display_name', 'person' => true],
            'general_income_source_report.updated' => ['label' => 'Business updated', 'icon' => 'folder', 'detail' => 'display_name', 'person' => true],
            'income_source.contributor_added' => ['label' => 'Business contributor added', 'icon' => 'users', 'person' => true],
            'income_source.contributor_removed' => ['label' => 'Business contributor removed', 'icon' => 'users', 'person' => true],
            'residence_check.created' => ['label' => 'Residence Check saved', 'icon' => 'home', 'person' => true],
            'residence_check.updated' => ['label' => 'Residence Check saved', 'icon' => 'home', 'person' => true],
            'residence_check.deleted' => ['label' => 'Residence Check removed', 'icon' => 'trash', 'detail' => 'location', 'person' => true],
            'business_check.created' => ['label' => 'Business Check saved', 'icon' => 'building', 'person' => true],
            'business_check.updated' => ['label' => 'Business Check saved', 'icon' => 'building', 'person' => true],
            'business_check.deleted' => ['label' => 'Business Check removed', 'icon' => 'trash', 'detail' => 'location', 'person' => true],
            'residence_check.contributor_added' => ['label' => 'Residence Check contributor added', 'icon' => 'users', 'person' => true],
            'residence_check.contributor_removed' => ['label' => 'Residence Check contributor removed', 'icon' => 'users', 'person' => true],
            'business_check.contributor_added' => ['label' => 'Business Check contributor added', 'icon' => 'users', 'person' => true],
            'business_check.contributor_removed' => ['label' => 'Business Check contributor removed', 'icon' => 'users', 'person' => true],
            'ci_activity.completed' => ['label' => 'CI Activity completed', 'icon' => 'activity', 'detail' => 'activity_title', 'person' => true],
            'ci_activity.updated' => ['label' => 'CI Activity updated', 'icon' => 'activity', 'detail' => 'activity_title', 'person' => true],
            'media.uploaded' => ['icon' => 'media', 'person' => true],
            'media.removed' => ['icon' => 'trash', 'person' => true],
        ];

        return AuditLog::query()
            ->where('client_folder_id', $folder->id)
            ->whereIn('action', array_keys($labels))
            ->with('user:id,full_name')
            ->latest('created_at')
            ->latest('id')
            ->limit(30)
            ->get(['id', 'user_id', 'module', 'action', 'metadata', 'created_at'])
            ->filter(function (AuditLog $event) use ($personId): bool {
                $metadata = (array) $event->metadata;

                if (in_array($event->action, self::CO_MAKER_LIFECYCLE_ACTIONS, true)) {
                    // Visible as folder-level participant-management activity from the Applicant's
                    // view, but scoped to the exact affected Co-Maker everywhere else — never
                    // shown as though it belongs to a different Co-Maker.
                    return $personId === null || (array_key_exists('co_maker_id', $metadata) && $metadata['co_maker_id'] === $personId);
                }

                if ($event->module === 'client_folders') {
                    return true;
                }

                return array_key_exists('co_maker_id', $metadata) && $metadata['co_maker_id'] === $personId;
            })
            ->take(20)
            ->values()
            ->map(function (AuditLog $event) use ($labels, $personContextLabel) {
                $definition = $labels[$event->action];
                $metadata = (array) $event->metadata;

                $detail = match (true) {
                    $event->action === 'cibi_report.signatory_reassigned' => $this->signatoryReassignmentDetail($metadata),
                    isset($definition['detail']) => data_get($metadata, $definition['detail']),
                    default => null,
                };

                return (object) [
                    'label' => in_array($event->action, self::MEDIA_ACTIONS, true) ? $this->mediaActivityLabel($event->action, $metadata) : $definition['label'],
                    // The administrative reassignment reason is captured in metadata for the full
                    // audit record, but deliberately never surfaces here — Recent Activity is the
                    // concise operational notice, not the Admin audit trail.
                    'detail' => $detail,
                    'personContext' => ($definition['person'] ?? false) ? $personContextLabel : null,
                    'icon' => $definition['icon'],
                    'user' => $event->user,
                    'actorLabel' => $event->action === 'media.uploaded' ? 'Uploaded by' : 'by',
                    'created_at' => $event->created_at,
                ];
            });
    }

    /** Distinguishes Residence vs Business photo activity using the media's own saved category. */
    private function mediaActivityLabel(string $action, array $metadata): string
    {
        $subject = match (data_get($metadata, 'category')) {
            'residence' => 'Residence photo',
            'business' => 'Business photo',
            default => 'Photo',
        };

        return $subject.' '.($action === 'media.uploaded' ? 'uploaded' : 'removed');
    }

    /** Old → new signatory, from name snapshots captured at reassignment time (never a live lookup). */
    private function signatoryReassignmentDetail(array $metadata): ?string
    {
        $old = data_get($metadata, 'old_signatory_name');
        $new = data_get($metadata, 'new_signatory_name');

        return $old && $new ? "{$old} \u{2192} {$new}" : null;
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
