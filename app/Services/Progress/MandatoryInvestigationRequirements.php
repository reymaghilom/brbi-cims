<?php

namespace App\Services\Progress;

use App\Enums\ActivityStatus;
use App\Enums\RecordState;
use App\Models\ActivityDefinition;
use App\Models\ClientFolder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The single definition of "mandatory investigation work complete", shared by the Client Folder
 * progress/status (ClientProgressService) and the Dashboard In Progress KPI so the two never drift.
 *
 * - Applicant (co_maker_id NULL): CIBI, Business Report, Residence Check, Business Check, Barangay
 *   Check, Neighbor Check and Bank / Coop Check.
 * - Every existing Co-Maker (their own exact co_maker_id): CIBI, Residence Check, Barangay Check and
 *   Neighbor Check. A Co-Maker may legitimately have no business.
 *
 * Asset Check is optional. Each requirement uses the owning module's existing "done" state: a
 * Complete CIBI; a Complete business income source whose Business Report was explicitly saved (the
 * ClientFolderOverview rule); a saved Residence / Business Check; a Completed activity (Bank / Coop's
 * status is already derived from its targets). Every predicate is a correlated EXISTS against
 * `client_folders.id`, so it must be applied to a client_folders query.
 */
class MandatoryInvestigationRequirements
{
    public const APPLICANT = [
        'cibi' => 'CI / BI Report',
        'business_report' => 'Business Report',
        'residence_check' => 'Residence Check',
        'business_check' => 'Business Check',
        'barangay_check' => 'Barangay Check',
        'neighbor_check' => 'Neighbor Check',
        'bank_coop_check' => 'Bank / Coop Check',
    ];

    public const CO_MAKER = [
        'cibi' => 'CI / BI Report',
        'residence_check' => 'Residence Check',
        'barangay_check' => 'Barangay Check',
        'neighbor_check' => 'Neighbor Check',
    ];

    /**
     * Ordered label => satisfied map for one folder: the Applicant's seven, then four per existing
     * Co-Maker. One small EXISTS query per requirement; read-only.
     *
     * @return array<string, bool>
     */
    public function evaluate(ClientFolder $folder): array
    {
        $results = [];
        foreach (self::APPLICANT as $key => $label) {
            $results['Applicant: '.$label] = $this->satisfied($folder, $key, null);
        }

        foreach ($folder->coMakers()->get(['id', 'full_name']) as $coMaker) {
            foreach (self::CO_MAKER as $key => $label) {
                $results['Co-Maker '.($coMaker->full_name ?: '#'.$coMaker->id).' (#'.$coMaker->id.'): '.$label] = $this->satisfied($folder, $key, $coMaker->id);
            }
        }

        return $results;
    }

    /**
     * Constrains a client_folders query to rows where the given person has met every one of their
     * mandatory requirements. $coMaker is null for the Applicant, a Co-Maker id, or a correlated
     * column name (e.g. 'co_makers.id') when used inside a co_makers subquery.
     */
    public static function whereAllMet(Builder|QueryBuilder $query, int|string|null $coMaker): void
    {
        foreach (array_keys($coMaker === null ? self::APPLICANT : self::CO_MAKER) as $requirement) {
            self::whereMet($query, $requirement, $coMaker);
        }
    }

    public static function whereMet(Builder|QueryBuilder $query, string $requirement, int|string|null $coMaker): void
    {
        $query->whereExists(fn (QueryBuilder $record) => self::recordQuery($record, $requirement, $coMaker));
    }

    /**
     * Fills $record with the correlated record lookup for one requirement and person (from, joins and
     * wheres against `client_folders.id`). whereMet() wraps it in EXISTS; the Dashboard selects it
     * as a per-folder flag so every folder is evaluated in one query with these same predicates.
     */
    public static function recordQuery(QueryBuilder $record, string $requirement, int|string|null $coMaker): QueryBuilder
    {
        $person = fn (QueryBuilder $record, string $column) => match (true) {
            $coMaker === null => $record->whereNull($column),
            is_string($coMaker) => $record->whereColumn($column, $coMaker),
            default => $record->where($column, $coMaker),
        };
        $completedActivity = fn (string $code) => $person($record->from('ci_activities')
            ->join('activity_definitions', 'activity_definitions.id', '=', 'ci_activities.activity_definition_id')
            ->whereColumn('ci_activities.client_folder_id', 'client_folders.id')
            ->whereNull('ci_activities.deleted_at')
            ->where('activity_definitions.code', $code)
            ->where('ci_activities.status', ActivityStatus::Completed->value), 'ci_activities.co_maker_id');

        return match ($requirement) {
            'cibi' => $person($record->from('cibi_reports')
                ->whereColumn('cibi_reports.client_folder_id', 'client_folders.id')
                ->where('cibi_reports.state', RecordState::Complete->value), 'cibi_reports.co_maker_id'),
            'business_report' => $person($record->from('income_sources')
                ->whereColumn('income_sources.client_folder_id', 'client_folders.id')
                ->whereNull('income_sources.deleted_at')
                ->where('income_sources.state', RecordState::Complete->value)
                ->where('income_sources.revision', '>', 1)
                ->whereExists(fn (QueryBuilder $report) => $report->from('business_reports')
                    ->whereColumn('business_reports.income_source_id', 'income_sources.id')), 'income_sources.co_maker_id'),
            'residence_check' => $person($record->from('residence_checks')
                ->whereColumn('residence_checks.client_folder_id', 'client_folders.id'), 'residence_checks.co_maker_id'),
            'business_check' => $person($record->from('business_checks')
                ->whereColumn('business_checks.client_folder_id', 'client_folders.id'), 'business_checks.co_maker_id'),
            'barangay_check' => $completedActivity(ActivityDefinition::BARANGAY_CHECK_CODE),
            'neighbor_check' => $completedActivity(ActivityDefinition::NEIGHBOR_CHECK_CODE),
            'bank_coop_check' => $completedActivity(ActivityDefinition::BANK_COOP_CHECK_CODE),
        };
    }

    private function satisfied(ClientFolder $folder, string $requirement, ?int $coMakerId): bool
    {
        return ClientFolder::query()
            ->whereKey($folder->id)
            ->where(fn (Builder $query) => self::whereMet($query, $requirement, $coMakerId))
            ->exists();
    }
}
