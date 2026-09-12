<?php

namespace App\Services\ClientFolders;

use App\Models\AuditLog;
use App\Models\ClientFolder;
use App\Models\MediaReference;
use Illuminate\Support\Collection;

/**
 * Single source of truth for turning AuditLog rows into the Activity History
 * presentation used both by the full activities page and by the authoritative
 * "what changed" payload returned from a successful async CI activity mutation.
 */
class CiActivityHistoryFeed
{
    public static function watermark(): int
    {
        return (int) (AuditLog::query()->max('id') ?? 0);
    }

    /**
     * AuditLog rows created after $watermarkId for this exact folder/person,
     * newest first — matching the ordering used on the activities page.
     */
    public static function since(ClientFolder $folder, int $watermarkId, ?int $coMakerId): Collection
    {
        return AuditLog::query()
            ->where('client_folder_id', $folder->id)
            ->where('id', '>', $watermarkId)
            ->where(function ($query): void {
                $query
                    ->whereIn('module', ['ci_activities', 'activity_definitions'])
                    ->orWhere('action', 'media.uploaded');
            })
            ->with('user:id,full_name')
            ->oldest('id')
            ->get()
            ->filter(fn (AuditLog $event): bool => self::belongsToPerson($event, $coMakerId))
            ->map(fn (AuditLog $event) => self::map($event))
            ->reverse()
            ->values();
    }

    public static function belongsToPerson(AuditLog $event, ?int $coMakerId): bool
    {
        // Activity Type management edits the reusable ActivityDefinition, which is shared
        // configuration rather than one person's record, so those events are shown in every
        // Applicant/Co-Maker context of the folder they were performed from.
        if ($event->module === 'activity_definitions') {
            return true;
        }

        $metadata = (array) $event->metadata;

        if ($event->action === 'media.uploaded') {
            $mediaReferenceId = (int) data_get($metadata, 'media_reference_id');

            return MediaReference::query()
                ->whereKey($mediaReferenceId)
                ->where('co_maker_id', $coMakerId)
                ->exists();
        }

        return array_key_exists('co_maker_id', $metadata) && $metadata['co_maker_id'] === $coMakerId;
    }

    public static function map(AuditLog $event): object
    {
        $metadata = (array) $event->metadata;
        $proofName = $event->action === 'media.uploaded'
            ? MediaReference::query()->whereKey((int) data_get($metadata, 'media_reference_id'))->value('file_name')
            : null;

        return self::mapWithProofName($event, $proofName);
    }

    /**
     * @param  Collection<int, string>  $proofNames  media_reference_id => file_name
     */
    public static function mapUsingProofNames(AuditLog $event, Collection $proofNames): object
    {
        $metadata = (array) $event->metadata;
        $proofName = $event->action === 'media.uploaded'
            ? ($proofNames[(int) data_get($metadata, 'media_reference_id')] ?? null)
            : null;

        return self::mapWithProofName($event, $proofName);
    }

    private static function mapWithProofName(AuditLog $event, ?string $proofName): object
    {
        $metadata = (array) $event->metadata;

        return (object) [
            'id' => $event->id,
            'label' => self::labelFor($event->action, $metadata),
            'detail' => match ($event->action) {
                'media.uploaded' => $proofName,
                'ci_activity.submitted' => collect([
                    filled(data_get($metadata, 'submitted_to')) ? 'Submitted to: '.data_get($metadata, 'submitted_to') : null,
                    data_get($metadata, 'submission_note'),
                ])->filter()->join(' — ') ?: null,
                default => null,
            },
            'tone' => match ($event->action) {
                'ci_activity.completed', 'ci_activity.bank_target_completed', 'ci_activity.asset_target_completed', 'ci_activity.submitted', 'media.uploaded' => 'success',
                'ci_activity.scheduled', 'ci_activity.rescheduled', 'ci_activity.reopened' => 'progress',
                default => 'neutral',
            },
            'user' => $event->user,
            'created_at' => $event->created_at,
        ];
    }

    /**
     * Resolve event wording only from metadata captured with that audit row. Missing historical
     * context deliberately falls back to the established generic wording instead of consulting a
     * mutable live activity/target or guessing which subtype an old Bank / Coop target represented.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function labelFor(string $action, array $metadata): string
    {
        $activityTitle = filled(data_get($metadata, 'activity_title'))
            ? (string) data_get($metadata, 'activity_title')
            : null;
        $assetTarget = filled(data_get($metadata, 'asset_target_label'))
            ? (string) data_get($metadata, 'asset_target_label')
            : null;
        $bankTarget = filled(data_get($metadata, 'bank_target_label'))
            ? (string) data_get($metadata, 'bank_target_label')
            : null;
        $bankTargetType = filled(data_get($metadata, 'bank_target_type_label'))
            ? (string) data_get($metadata, 'bank_target_type_label')
            : null;

        return match ($action) {
            'ci_activity.created' => ($activityTitle ?? 'CI Activity').' created',
            'ci_activity.scheduled' => ($activityTitle ?? 'CI Activity').' scheduled',
            'ci_activity.rescheduled' => ($activityTitle ?? 'CI Activity').' rescheduled',
            'ci_activity.completed' => ($activityTitle ?? 'CI Activity').' completed',
            'ci_activity.bank_target_completed' => self::targetEventLabel($bankTargetType, $bankTarget, 'completed', 'CI Activity completed'),
            'ci_activity.asset_target_completed' => self::targetEventLabel($activityTitle, $assetTarget, 'completed', 'CI Activity completed'),
            'ci_activity.asset_target_created' => self::targetEventLabel($activityTitle, $assetTarget, 'added', 'CI Activity updated'),
            'ci_activity.asset_target_updated' => self::targetEventLabel($activityTitle, $assetTarget, 'updated', 'CI Activity updated'),
            'ci_activity.asset_target_deleted' => self::targetEventLabel($activityTitle, $assetTarget, 'deleted', 'CI Activity updated'),
            'ci_activity.submitted' => ($activityTitle ?? 'CI Activity').' submitted to Credit Analyst',
            'ci_activity.reopened' => ($activityTitle ?? 'CI Activity').' reopened',
            'ci_activity.deleted' => ($activityTitle ?? 'CI Activity').' deleted',
            'ci_activity.assignment_changed' => ($activityTitle ?? 'CI Activity').' assignment updated',
            'activity_definition.created' => 'Created Activity Type "'.data_get($metadata, 'activity_title').'"',
            'activity_definition.renamed' => 'Updated Activity Type from "'.data_get($metadata, 'previous_name').'" to "'.data_get($metadata, 'activity_title').'"',
            'activity_definition.activated' => 'Activated Activity Type "'.data_get($metadata, 'activity_title').'"',
            'activity_definition.deactivated' => 'Deactivated Activity Type "'.data_get($metadata, 'activity_title').'"',
            'activity_definition.deleted' => 'Deleted Activity Type "'.data_get($metadata, 'activity_title').'"',
            'media.uploaded' => 'Proof uploaded',
            default => ($activityTitle ?? 'CI Activity').' updated',
        };
    }

    private static function targetEventLabel(?string $activityTitle, ?string $target, string $verb, string $fallback): string
    {
        if ($activityTitle && $target) {
            return $activityTitle.' — '.$target.' '.$verb;
        }

        if ($activityTitle) {
            return $activityTitle.' '.$verb;
        }

        // A saved target name is still exact context, even when an older row has no trustworthy
        // activity/subtype label. Preserve it without fabricating the missing type.
        if ($target) {
            return $target.' '.$verb;
        }

        return $fallback;
    }

    /**
     * Render the newest-first HTML for the given already-mapped history events,
     * using the exact same partial as the activities page's own history list.
     *
     * @param  Collection<int, object>  $events
     * @return array<int, string>
     */
    public static function renderHtml(Collection $events): array
    {
        return $events
            ->map(fn (object $event): string => view('client-folders.activities.partials.history-entry', ['event' => $event])->render())
            ->all();
    }
}
