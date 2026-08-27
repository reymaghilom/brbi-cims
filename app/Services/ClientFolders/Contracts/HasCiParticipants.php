<?php

namespace App\Services\ClientFolders\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Implemented by owning records (Business Report's IncomeSource, BusinessCheck, ResidenceCheck)
 * that support companion CI participants on top of their existing single "primary CI" column.
 * CIBI intentionally does not implement this — it stays single-CI.
 */
interface HasCiParticipants
{
    /** Companion CIs only. The primary CI is never stored here — see ciPrimaryUserId(). */
    public function contributors(): BelongsToMany;

    /**
     * The original creator/CI-in-charge for this record. Always first in the effective
     * participant list and can never be removed via companion sync. Null only if legacy data
     * has no resolvable actor at all.
     */
    public function ciPrimaryUserId(): ?int;
}
