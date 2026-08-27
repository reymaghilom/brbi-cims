<?php

namespace App\Services\ClientFolders;

use App\Models\ClientFolder;
use App\Models\CoMaker;
use Illuminate\Support\Carbon;

/**
 * Resolves the authoritative CI Date for whichever person a Residence Check is being encoded
 * for — the "Start Date of CI" already recorded on that exact person's own CI/BI Report
 * (`cibi_reports.start_date`, scoped by `co_maker_id` the same "null means Applicant" convention
 * used everywhere else). Unlike the address rule, both Applicant and Co-Maker use this same
 * lookup — there is no separate per-person fallback field, since Start Date only ever lives on
 * the CI/BI Report itself.
 */
class PersonCiDateResolver
{
    public static function resolve(ClientFolder $folder, ?CoMaker $activePerson): ?Carbon
    {
        return $folder->cibiReports()->where('co_maker_id', $activePerson?->id)->first()?->start_date;
    }
}
