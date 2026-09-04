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
                $query->where('module', 'ci_activities')->orWhere('action', 'media.uploaded');
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
            'label' => match ($event->action) {
                'ci_activity.created' => data_get($metadata, 'activity_title').' created',
                'ci_activity.scheduled' => data_get($metadata, 'activity_title').' scheduled',
                'ci_activity.rescheduled' => data_get($metadata, 'activity_title').' rescheduled',
                'ci_activity.completed' => data_get($metadata, 'activity_title').' completed',
                'ci_activity.bank_target_completed' => data_get($metadata, 'bank_target_label', 'Bank / Coop target').' completed',
                'ci_activity.asset_target_completed' => data_get($metadata, 'asset_target_label', 'Asset target').' completed',
                'ci_activity.asset_target_created' => data_get($metadata, 'asset_target_label', 'Asset target').' added',
                'ci_activity.asset_target_updated' => data_get($metadata, 'asset_target_label', 'Asset target').' updated',
                'ci_activity.asset_target_deleted' => data_get($metadata, 'asset_target_label', 'Asset target').' deleted',
                'ci_activity.submitted' => data_get($metadata, 'activity_title').' submitted to Credit Analyst',
                'ci_activity.reopened' => data_get($metadata, 'activity_title').' reopened',
                'ci_activity.deleted' => data_get($metadata, 'activity_title').' deleted',
                'ci_activity.assignment_changed' => data_get($metadata, 'activity_title', 'CI Activity').' assignment updated',
                'media.uploaded' => 'Proof uploaded',
                default => data_get($metadata, 'activity_title', 'CI Activity').' updated',
            },
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
