<?php

namespace App\Services\ClientFolders;

use App\Models\ClientFolder;
use App\Models\IncomeSource;

class BusinessReportInitialData
{
    /**
     * Initial loan/application data from this exact person's first-priority saved Business Report.
     * Saved CIBI values never pass through here, so this is creation-time prefill only.
     *
     * @return array{branch_name: ?string, account_officer_name: ?string, amount_applied: mixed}|null
     */
    public function for(ClientFolder $folder, ?int $coMakerId): ?array
    {
        $source = $folder->incomeSources()
            ->where('co_maker_id', $coMakerId)
            ->whereHas('businessReport')
            ->where('revision', '>', 1)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first(['id', 'branch_name', 'account_officer_name', 'amount_applied']);

        if (! $source instanceof IncomeSource) {
            return null;
        }

        return $source->only(['branch_name', 'account_officer_name', 'amount_applied']);
    }
}
