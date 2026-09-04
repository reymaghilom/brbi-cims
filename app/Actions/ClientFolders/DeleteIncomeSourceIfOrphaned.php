<?php

namespace App\Actions\ClientFolders;

use App\Models\BusinessCheck;
use App\Models\BusinessReport;
use App\Models\IncomeSource;

/**
 * Shared cleanup step for the hard-delete Business Report / Business Check paths: income_source_id
 * is the stable identity the two sides share, and business_checks.income_source_id is a required,
 * cascadeOnDelete FK — force-deleting an IncomeSource while a Report or Check still points at it
 * would cascade-delete that surviving counterpart too. This only ever removes the IncomeSource once
 * neither side meaningfully references it any more, and only ever via forceDelete (never a soft
 * delete) since a dedicated-business IncomeSource that reaches this point is never meant to
 * reappear in the Recycle Bin — see DeleteBusinessCheck / DeleteBusinessReport.
 *
 * A BusinessReport row's mere existence is NOT enough to count as "has a report": CreateIncomeSource
 * always creates one immediately (revision stays 1 until SaveBusinessIncomeSource actually runs at
 * least once — see IncomeSourceController::dedicatedSources()'s same convention), so a Check-first
 * business whose Check gets deleted before its Report was ever explicitly saved would otherwise be
 * judged "not orphaned" forever because of that untouched draft shell — a true orphan that can never
 * be cleaned up and keeps showing up as a ghost candidate everywhere IncomeSource rows are listed. A
 * BusinessCheck row has no equivalent draft-shell concept (it only ever exists once explicitly
 * saved), so its mere existence is still a valid "has a check" signal.
 */
class DeleteIncomeSourceIfOrphaned
{
    public function execute(IncomeSource $source): bool
    {
        $hasReport = $source->revision > 1 && BusinessReport::query()->where('income_source_id', $source->id)->exists();
        $hasCheck = BusinessCheck::query()->where('income_source_id', $source->id)->exists();
        if ($hasReport || $hasCheck) {
            return false;
        }

        $source->forceDelete();

        return true;
    }
}
