<?php

namespace App\Services\ClientFolders;

/**
 * Structured outcome of a companion CI sync, kept audit-ready so callers can log exactly what
 * changed instead of re-deriving it from before/after collections themselves.
 */
class CiParticipantSyncResult
{
    /**
     * @param  array<int, int>  $before  Ordered participant ids (primary first) prior to the sync.
     * @param  array<int, int>  $after  Ordered participant ids (primary first) after the sync.
     * @param  array<int, int>  $added  Companion user ids newly present after the sync.
     * @param  array<int, int>  $removed  Companion user ids no longer present after the sync.
     */
    public function __construct(
        public readonly array $before,
        public readonly array $after,
        public readonly array $added,
        public readonly array $removed,
    ) {}
}
