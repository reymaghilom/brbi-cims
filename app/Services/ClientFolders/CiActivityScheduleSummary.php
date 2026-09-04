<?php

namespace App\Services\ClientFolders;

use Illuminate\Support\Collection;

/**
 * Derives a presentation-only "what's next" schedule summary for Bank/Asset
 * Check activities from their live, currently-Scheduled child targets.
 *
 * This never reads or writes CiActivity.scheduled_at for Bank/Asset checks —
 * that column intentionally stays NULL for them; the authoritative schedule
 * always lives on the individual target row. Reused identically by the main
 * activities table and by each tracker's own auto-update payload so the
 * "earliest scheduled target wins, ties by id" rule exists in exactly one
 * place.
 *
 * @template T of \App\Models\CiActivityBankTarget|\App\Models\CiActivityAssetTarget
 */
class CiActivityScheduleSummary
{
    /**
     * @param  Collection<int, mixed>  $currentlyScheduledTargets  targets already filtered to
     *                                                             status=Scheduled AND scheduled_at is not null
     */
    public static function fromCurrentTargets(Collection $currentlyScheduledTargets): object
    {
        $sorted = $currentlyScheduledTargets
            ->sortBy(fn ($target) => $target->scheduled_at->timestamp)
            ->values();

        $primary = $sorted->first();

        return (object) [
            'has_schedule' => $primary !== null,
            'primary_label' => $primary?->targetLabel(),
            'scheduled_at' => $primary?->scheduled_at,
            'scheduled_has_time' => $primary !== null && (bool) $primary->scheduled_has_time,
            'additional_count' => max(0, $sorted->count() - 1),
        ];
    }
}
