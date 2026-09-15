<?php

namespace App\Services\Dashboard;

use App\Enums\RecordState;
use App\Models\BusinessCheck;
use App\Models\CibiReport;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use App\Models\IncomeSource;
use App\Models\ResidenceCheck;
use App\Services\Progress\MandatoryInvestigationRequirements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardProgressData
{
    /**
     * Mandatory investigation progress for every folder, set-based: one query returns each folder's
     * seven Applicant flags and one returns each Co-Maker's four, all built from
     * MandatoryInvestigationRequirements' own predicates (the definition folder progress and the
     * In Progress KPI share). A folder is In Progress exactly when something here is missing.
     *
     * @param  Collection<int, int>  $folderIds
     * @return array<int, array{completed: int, total: int, percent: int, missing: list<string>}>
     */
    public function mandatory(Collection $folderIds): array
    {
        if ($folderIds->isEmpty()) {
            return [];
        }

        $flag = fn (string $requirement, ?string $coMakerColumn) => fn (QueryBuilder $record) => MandatoryInvestigationRequirements::recordQuery($record, $requirement, $coMakerColumn)->selectRaw('1')->limit(1);

        $applicants = DB::table('client_folders')->whereIn('client_folders.id', $folderIds)->select('client_folders.id');
        foreach (array_keys(MandatoryInvestigationRequirements::APPLICANT) as $requirement) {
            $applicants->selectSub($flag($requirement, null), 'met_'.$requirement);
        }
        $coMakers = DB::table('co_makers')
            ->join('client_folders', 'client_folders.id', '=', 'co_makers.client_folder_id')
            ->whereIn('client_folders.id', $folderIds)
            ->orderBy('co_makers.id')
            ->select('co_makers.id', 'co_makers.client_folder_id', 'co_makers.full_name');
        foreach (array_keys(MandatoryInvestigationRequirements::CO_MAKER) as $requirement) {
            $coMakers->selectSub($flag($requirement, 'co_makers.id'), 'met_'.$requirement);
        }
        $coMakersByFolder = $coMakers->get()->groupBy('client_folder_id');

        return $applicants->get()->mapWithKeys(function (object $folder) use ($coMakersByFolder): array {
            $missing = [];
            $total = 0;
            foreach (MandatoryInvestigationRequirements::APPLICANT as $requirement => $label) {
                $total++;
                if (! $folder->{'met_'.$requirement}) {
                    $missing[] = $label;
                }
            }
            foreach ($coMakersByFolder->get($folder->id, collect()) as $coMaker) {
                foreach (MandatoryInvestigationRequirements::CO_MAKER as $requirement => $label) {
                    $total++;
                    if (! $coMaker->{'met_'.$requirement}) {
                        $missing[] = 'Co-Maker: '.$coMaker->full_name.' — '.$label;
                    }
                }
            }
            $completed = $total - count($missing);

            return [(int) $folder->id => ['completed' => $completed, 'total' => $total, 'percent' => (int) round($completed / $total * 100), 'missing' => $missing]];
        })->all();
    }

    /**
     * The three PROGRESS STATUSES of a Client Folder, as one mutually exclusive distribution over
     * the same active-folder set the Active Client Folders KPI counts - so the slices always add up
     * to the folder total and every percentage is that slice over that same total.
     *
     * All three read one authoritative source, mandatory(), and nothing else:
     *
     * - Not Started: 0% - no mandatory requirement met yet. A brand-new folder lives here,
     *   auto-generated pristine Barangay / Neighbor rows included, since those satisfy nothing.
     * - In Progress: isInProgress(), the IDENTICAL predicate the In Progress KPI is counted with,
     *   applied to the identical array. The slice and the card are therefore the same folder set,
     *   not two formulas that happen to agree.
     * - Completed: 100% - every mandatory requirement met. Deliberately the progress result rather
     *   than the stored ClientFolderStatus: the two are kept in step by
     *   ClientProgressService::recalculate(), and where they ever disagree the calculation is the
     *   authority this chart reports.
     *
     * Needs Attention is deliberately NOT a slice here. It is an overdue FLAG that cuts across all
     * three statuses - a folder can be half-finished and overdue, or finished and overdue on
     * optional Asset work - so making it a mutually exclusive bucket was what pulled overdue
     * folders out of the In Progress slice and broke agreement with the In Progress KPI. It keeps
     * its own KPI card, its own count and its own detail modal; see DashboardWorkQueue::needsAttention().
     *
     * @param  Collection<int, ClientFolder>  $folders
     * @param  array<int, array{completed: int, total: int, percent: int, missing: list<string>}>  $mandatory
     */
    public function workload(Collection $folders, array $mandatory): array
    {
        $buckets = ['not_started' => 0, 'in_progress' => 0, 'completed' => 0];

        foreach ($folders as $folder) {
            $progress = $mandatory[$folder->id] ?? null;
            $bucket = match (true) {
                $this->isInProgress($progress) => 'in_progress',
                $progress !== null && $progress['missing'] === [] => 'completed',
                default => 'not_started',
            };
            $buckets[$bucket]++;
        }

        $total = $folders->count();

        return [
            'total' => $total,
            'segments' => collect([
                ['key' => 'not_started', 'label' => 'Not Started', 'tone' => 'amber'],
                ['key' => 'in_progress', 'label' => 'In Progress', 'tone' => 'brand'],
                ['key' => 'completed', 'label' => 'Completed', 'tone' => 'success'],
            ])->map(fn (array $segment): array => $segment + [
                'count' => $buckets[$segment['key']],
                'percent' => $this->percent($buckets[$segment['key']], $total),
            ])->all(),
        ];
    }

    /**
     * Each bar is completed-over-applicable using that module's authoritative obligation: CI/BI,
     * Residence and CI Activities include required people/work even before a row exists, while a
     * client with no business income source does not create a Business Check obligation. A category
     * with no applicable work reports 0% and says so in the view.
     *
     * @param  Collection<int, int>  $folderIds
     */
    public function activity(Collection $folderIds): array
    {
        $folderCount = $folderIds->count();

        // A CI/BI Report is required once PER PERSON - the Applicant of every folder in scope, plus
        // every existing Co-Maker (MandatoryInvestigationRequirements lists 'cibi' under both). The
        // denominator therefore counts people, not rows: a person whose report has not been created
        // yet is exactly the outstanding work this bar exists to show. Counting existing
        // cibi_reports rows instead made the bar read "11 of 11 = 100%" whenever every report that
        // happened to exist was finished, however many folders had none at all.
        //
        // Generated PDF/Excel output lives in generated_reports and is not consulted here at all.
        $coMakerCount = CoMaker::query()->whereIn('client_folder_id', $folderIds)->count();
        $cibiRequired = $folderCount + $coMakerCount;

        // The numerator follows the identical person-level rule, so it is counted per LOGICAL
        // PERSON rather than per row. A plain row count is NOT safe here, and the composite unique
        // index on (client_folder_id, co_maker_id) does not make it safe: SQL treats NULLs as
        // distinct inside a UNIQUE index, so that index constrains Co-Maker rows but lets one
        // folder hold any number of APPLICANT rows, every one of them with co_maker_id IS NULL.
        // GROUP BY is the opposite - it groups NULLs together - so one group per (folder, person)
        // is exactly the rule this bar needs, and it behaves identically on SQLite, MySQL and
        // PostgreSQL. Counting those groups through a subquery keeps this to a single query with
        // no model hydration.
        //
        // Nothing else can slip in: cibi_reports has no soft deletes, so a deleted report leaves
        // no countable row behind, and co_maker_id is a cascadeOnDelete foreign key, so a report
        // can never outlive the Co-Maker it belongs to and stand for a person no longer in scope.
        $cibiComplete = DB::query()->fromSub(
            CibiReport::query()
                ->whereIn('client_folder_id', $folderIds)
                ->where('state', RecordState::Complete)
                ->groupBy('client_folder_id', 'co_maker_id')
                ->select('client_folder_id', 'co_maker_id')
                ->toBase(),
            'completed_cibi'
        )->count();

        // Residence Check uses the same saved-row completion predicate and exact-person identity as
        // MandatoryInvestigationRequirements: one requirement for every Applicant plus one for
        // every Co-Maker belonging to an in-scope folder. Missing rows remain in the denominator.
        // Grouping nullable co_maker_id deliberately collapses duplicate Applicant rows as well as
        // duplicate Co-Maker rows, while the EXISTS guard prevents a malformed cross-folder
        // co_maker_id from representing a person who is not actually required by that folder.
        $residenceRequired = $folderCount + $coMakerCount;
        $residenceChecked = DB::query()->fromSub(
            ResidenceCheck::query()
                ->whereIn('client_folder_id', $folderIds)
                ->where(fn (Builder $person) => $person
                    ->whereNull('co_maker_id')
                    ->orWhereExists(fn (QueryBuilder $coMaker) => $coMaker
                        ->selectRaw('1')
                        ->from('co_makers')
                        ->whereColumn('co_makers.id', 'residence_checks.co_maker_id')
                        ->whereColumn('co_makers.client_folder_id', 'residence_checks.client_folder_id')))
                ->groupBy('client_folder_id', 'co_maker_id')
                ->select('client_folder_id', 'co_maker_id')
                ->toBase(),
            'completed_residence_checks'
        )->count();

        $businessTotal = IncomeSource::query()->whereIn('client_folder_id', $folderIds)->count();
        $businessChecked = BusinessCheck::query()
            ->whereIn('client_folder_id', $folderIds)
            ->whereNotNull('income_source_id')
            ->distinct()
            ->count('income_source_id');

        $activities = $this->mandatoryActivity($folderIds, $coMakerCount);

        return [
            $this->progressBar('CI/BI Report', $cibiComplete, $cibiRequired, 'CI/BI Reports'),
            $this->progressBar('Residence Check', $residenceChecked, $residenceRequired, 'required Residence Checks'),
            $this->progressBar('Business Check', $businessChecked, $businessTotal, 'businesses'),
            $this->progressBar('CI Activities', $activities['completed'], $activities['total'], 'required activities'),
        ];
    }

    /**
     * THE In Progress rule, in one place: investigation work has actually STARTED but is not yet
     * finished - mandatory progress strictly between 0% and 100%. Every reader of "In Progress"
     * (the KPI, its detail modal and the Workload chart's slice) calls this, so the three can never
     * describe different folder sets.
     *
     * It compares `completed` against `total` rather than the rounded `percent`, which is the same
     * question asked exactly: a folder with one requirement met out of a very long list would round
     * to 0%, and one with a single requirement left would round to 100%, yet neither has actually
     * started-but-finished or finished. The display percentage stays exactly as it was.
     *
     * A 0% folder is deliberately NOT In Progress: being active, incomplete, or merely holding
     * auto-generated pristine activity rows is not the same as having started. Those folders are
     * the Workload chart's existing Pending slice.
     *
     * @param  array{completed: int, total: int, percent: int, missing: list<string>}|null  $progress
     */
    public function isInProgress(?array $progress): bool
    {
        return $progress !== null && $progress['completed'] > 0 && $progress['missing'] !== [];
    }

    /**
     * Counts mandatory CI Activity obligations, including those with no row yet, using the exact
     * applicability and completion predicates owned by MandatoryInvestigationRequirements.
     * Applicant requirements are Barangay, Neighbor and one Bank / Coop parent; Co-Makers require
     * only Barangay and Neighbor. EXISTS flags make duplicate rows count once and preserve exact
     * Applicant / Co-Maker identity. Asset and every custom activity are outside these maps.
     *
     * @param  Collection<int, int>  $folderIds
     * @return array{completed: int, total: int}
     */
    private function mandatoryActivity(Collection $folderIds, int $coMakerCount): array
    {
        $applicantRequirements = MandatoryInvestigationRequirements::ciActivityRequirements(null);
        $coMakerRequirements = MandatoryInvestigationRequirements::ciActivityRequirements('co_makers.id');
        $total = ($folderIds->count() * count($applicantRequirements))
            + ($coMakerCount * count($coMakerRequirements));

        if ($folderIds->isEmpty()) {
            return ['completed' => 0, 'total' => $total];
        }

        $flag = fn (string $requirement, ?string $coMakerColumn) => fn (QueryBuilder $record) => MandatoryInvestigationRequirements::recordQuery($record, $requirement, $coMakerColumn)->selectRaw('1')->limit(1);
        $sumFlags = fn (Collection $people, array $requirements): int => (int) $people->sum(
            fn (object $person): int => collect(array_keys($requirements))->sum(
                fn (string $requirement): int => (int) (bool) $person->{'met_'.$requirement}
            )
        );

        $applicants = DB::table('client_folders')->whereIn('client_folders.id', $folderIds)->select('client_folders.id');
        foreach (array_keys($applicantRequirements) as $requirement) {
            $applicants->selectSub($flag($requirement, null), 'met_'.$requirement);
        }

        $coMakers = DB::table('co_makers')
            ->join('client_folders', 'client_folders.id', '=', 'co_makers.client_folder_id')
            ->whereIn('client_folders.id', $folderIds)
            ->select('co_makers.id');
        foreach (array_keys($coMakerRequirements) as $requirement) {
            $coMakers->selectSub($flag($requirement, 'co_makers.id'), 'met_'.$requirement);
        }

        return [
            'completed' => $sumFlags($applicants->get(), $applicantRequirements)
                + $sumFlags($coMakers->get(), $coMakerRequirements),
            'total' => $total,
        ];
    }

    private function progressBar(string $label, int $completed, int $applicable, string $unit): array
    {
        return [
            'label' => $label,
            'completed' => $completed,
            'applicable' => $applicable,
            'unit' => $unit,
            'percent' => $this->percent($completed, $applicable),
        ];
    }

    private function percent(int $value, int $total): int
    {
        return $total > 0 ? (int) round($value / $total * 100) : 0;
    }
}
