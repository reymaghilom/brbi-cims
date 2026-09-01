<?php

namespace App\Services\ClientFolders;

use App\Models\ActivityDefinition;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use Illuminate\Database\Eloquent\Model;

class BankInstitutionPrefill
{
    private const ROW_LIMIT = 50;

    /** @return array<int, array{institution_name: string, branch_location: string|null, source: string}> */
    public function bankTargetsFromCibi(ClientFolder $folder, ?CoMaker $person, iterable $existingTargets = []): array
    {
        $this->assertPersonBelongsToFolder($folder, $person);
        $report = $folder->cibiReport()->where('co_maker_id', $person?->id)->first();
        if (! $report) {
            return [];
        }

        $candidates = $report->bankAccounts()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['institution', 'branch'])
            ->map(fn ($row): array => [
                'institution' => $row->institution,
                'branch' => $row->branch,
                'source' => 'CIBI Bank / Financial Institution',
            ])
            ->all();

        $loanCandidates = $report->loanRecords()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['institution'])
            ->map(fn ($row): array => [
                'institution' => $row->institution,
                'branch' => null,
                'source' => 'CIBI Credit / Loan',
            ])
            ->all();

        $destination = collect($existingTargets)->map(fn ($target): array => [
            'institution' => $this->value($target, 'institution_name'),
            'branch' => $this->value($target, 'branch_location'),
        ])->all();

        return array_map(
            fn (array $candidate): array => [
                'institution_name' => $candidate['institution'],
                'branch_location' => $candidate['branch'],
                'source' => $candidate['source'],
            ],
            $this->missingPairs(array_merge($candidates, $loanCandidates), $destination),
        );
    }

    /** @return array<int, array{institution: string, branch: string|null}> */
    public function cibiBankAccountsFromTargets(ClientFolder $folder, ?CoMaker $person, iterable $existingAccounts = []): array
    {
        $targets = $this->bankTargets($folder, $person);
        $destination = collect($existingAccounts)->map(fn ($account): array => [
            'institution' => $this->value($account, 'institution'),
            'branch' => $this->value($account, 'branch'),
        ])->all();

        return array_map(
            fn (array $candidate): array => [
                'institution' => $candidate['institution'],
                'branch' => $candidate['branch'],
            ],
            $this->missingPairs($targets, $destination),
        );
    }

    /** @return array<int, array{institution: string}> */
    public function cibiLoanRecordsFromTargets(ClientFolder $folder, ?CoMaker $person, iterable $existingLoans = []): array
    {
        $targets = $this->bankTargets($folder, $person);
        $existingLoans = collect($existingLoans);
        $existingNames = $existingLoans
            ->map(fn ($loan): string => $this->normalize($this->value($loan, 'institution')))
            ->filter()
            ->flip();
        $seen = [];
        $candidates = [];

        foreach ($targets as $target) {
            $key = $this->normalize($target['institution']);
            if ($key === '' || isset($seen[$key]) || $existingNames->has($key)) {
                continue;
            }

            $seen[$key] = true;
            $candidates[] = ['institution' => $target['institution']];
        }

        $remaining = max(0, self::ROW_LIMIT - $existingLoans->count());

        return array_slice($candidates, 0, $remaining);
    }

    /** @return array<int, array{institution: string, branch: string|null, source: string}> */
    private function bankTargets(ClientFolder $folder, ?CoMaker $person): array
    {
        $this->assertPersonBelongsToFolder($folder, $person);

        return $folder->activities()
            ->where('co_maker_id', $person?->id)
            ->whereHas('definition', fn ($query) => $query->where('code', ActivityDefinition::BANK_COOP_CHECK_CODE))
            ->with(['bankTargets' => fn ($query) => $query->oldest('id')])
            ->oldest('id')
            ->get()
            ->flatMap(fn ($activity) => $activity->bankTargets->map(fn ($target): array => [
                'institution' => $target->institution_name,
                'branch' => $target->branch_location,
                'source' => 'Bank / Coop Check',
            ]))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{institution: mixed, branch?: mixed, source?: string}>  $candidates
     * @param  array<int, array{institution: mixed, branch?: mixed}>  $destination
     * @return array<int, array{institution: string, branch: string|null, source: string}>
     */
    private function missingPairs(array $candidates, array $destination): array
    {
        $destinationCount = count($destination);
        $candidates = $this->deduplicatePairs($candidates);
        $destination = $this->deduplicatePairs($destination);
        $destinationByInstitution = collect($destination)->groupBy(fn (array $row): string => $this->normalize($row['institution']));
        $missing = array_values(array_filter($candidates, function (array $candidate) use ($destinationByInstitution): bool {
            $sameInstitution = $destinationByInstitution->get($this->normalize($candidate['institution']), collect());
            if ($sameInstitution->isEmpty()) {
                return true;
            }

            $candidateBranch = $this->normalize($candidate['branch']);
            $destinationBranches = $sameInstitution
                ->map(fn (array $row): string => $this->normalize($row['branch']))
                ->unique()
                ->values();

            if ($destinationBranches->contains($candidateBranch)) {
                return false;
            }

            // A single branch-bearing row and a blank row are an unambiguous representation of
            // the same institution. Do not manufacture a duplicate, and never overwrite either.
            if ($candidateBranch === '' && $destinationBranches->count() === 1) {
                return false;
            }

            return ! ($destinationBranches->count() === 1 && $destinationBranches->first() === '');
        }));
        $remaining = max(0, self::ROW_LIMIT - $destinationCount);

        return array_slice($missing, 0, $remaining);
    }

    /**
     * @param  array<int, array{institution: mixed, branch?: mixed, source?: string}>  $rows
     * @return array<int, array{institution: string, branch: string|null, source: string}>
     */
    private function deduplicatePairs(array $rows): array
    {
        $unique = [];
        $seen = [];

        foreach ($rows as $row) {
            $institution = is_string($row['institution'] ?? null) ? $row['institution'] : '';
            $branch = is_string($row['branch'] ?? null) && $this->normalize($row['branch']) !== '' ? $row['branch'] : null;
            $institutionKey = $this->normalize($institution);
            if ($institutionKey === '') {
                continue;
            }

            $pairKey = $institutionKey."\0".$this->normalize($branch);
            if (isset($seen[$pairKey])) {
                continue;
            }

            $seen[$pairKey] = true;
            $unique[] = [
                'institution' => $institution,
                'branch' => $branch,
                'source' => $row['source'] ?? '',
            ];
        }

        $explicitBranchCounts = collect($unique)
            ->groupBy(fn (array $row): string => $this->normalize($row['institution']))
            ->map(fn ($group): int => $group
                ->map(fn (array $row): string => $this->normalize($row['branch']))
                ->filter()
                ->unique()
                ->count());

        return array_values(array_filter($unique, function (array $row) use ($explicitBranchCounts): bool {
            if ($this->normalize($row['branch']) !== '') {
                return true;
            }

            // A branchless source is redundant only when exactly one explicit branch exists.
            // With multiple branches it remains separate because choosing one would be a guess.
            return $explicitBranchCounts->get($this->normalize($row['institution']), 0) !== 1;
        }));
    }

    private function normalize(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $value)), 'UTF-8');
    }

    private function value(mixed $record, string $field): mixed
    {
        return $record instanceof Model ? $record->getAttribute($field) : data_get($record, $field);
    }

    private function assertPersonBelongsToFolder(ClientFolder $folder, ?CoMaker $person): void
    {
        abort_if($person && (int) $person->client_folder_id !== (int) $folder->id, 404);
    }
}
