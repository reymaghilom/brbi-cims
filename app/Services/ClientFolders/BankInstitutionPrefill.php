<?php

namespace App\Services\ClientFolders;

use App\Models\ActivityDefinition;
use App\Models\CiActivityBankTarget;
use App\Models\ClientFolder;
use App\Models\CoMaker;
use Illuminate\Database\Eloquent\Model;

class BankInstitutionPrefill
{
    private const ROW_LIMIT = 50;

    /** @return array<int, array{inquiry_type: string, institution_name: string, branch_location: string|null, source: string}> */
    public function bankTargetsFromCibi(ClientFolder $folder, ?CoMaker $person, iterable $existingTargets = []): array
    {
        $this->assertPersonBelongsToFolder($folder, $person);
        $report = $folder->cibiReport()->where('co_maker_id', $person?->id)->first();
        if (! $report) {
            return [];
        }

        $candidates = $report->bankAccounts()->orderBy('sort_order')->orderBy('id')->get(['institution', 'branch'])
            ->map(fn ($row): array => [
                'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
                'institution' => $row->institution,
                'branch' => $row->branch,
                'source' => 'CIBI Bank / Financial Institution',
            ]);
        $candidates->push(...$report->loanRecords()->orderBy('sort_order')->orderBy('id')->get(['institution'])
            ->map(fn ($row): array => [
                'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY,
                'institution' => $row->institution,
                'branch' => null,
                'source' => 'CIBI Credit / Loan',
            ]));

        $destination = collect($existingTargets)->map(fn ($target): array => [
            'inquiry_type' => $this->value($target, 'inquiry_type') ?: CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution' => $this->value($target, 'institution_name'),
            'branch' => $this->value($target, 'branch_location'),
        ])->all();

        return array_map(fn (array $candidate): array => [
            'inquiry_type' => $candidate['inquiry_type'],
            'institution_name' => $candidate['institution'],
            'branch_location' => $candidate['branch'],
            'source' => $candidate['source'],
        ], $this->missingTargets($candidates->all(), $destination, count($destination)));
    }

    /** @return array<int, array{institution: string, branch: string|null}> */
    public function cibiBankAccountsFromTargets(ClientFolder $folder, ?CoMaker $person, iterable $existingAccounts = []): array
    {
        $existingAccounts = collect($existingAccounts);
        $destination = $existingAccounts->map(fn ($account): array => [
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK,
            'institution' => $this->value($account, 'institution'),
            'branch' => $this->value($account, 'branch'),
        ])->all();

        return array_map(fn (array $candidate): array => [
            'institution' => $candidate['institution'],
            'branch' => $candidate['branch'],
        ], $this->missingTargets(
            $this->bankTargets($folder, $person, CiActivityBankTarget::INQUIRY_TYPE_BANK_COOP_CHECK),
            $destination,
            $existingAccounts->count(),
        ));
    }

    /** @return array<int, array{institution: string}> */
    public function cibiLoanRecordsFromTargets(ClientFolder $folder, ?CoMaker $person, iterable $existingLoans = []): array
    {
        $existingLoans = collect($existingLoans);
        $destination = $existingLoans->map(fn ($loan): array => [
            'inquiry_type' => CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY,
            'institution' => $this->value($loan, 'institution'),
            'branch' => null,
        ])->all();

        return array_map(fn (array $candidate): array => ['institution' => $candidate['institution']], $this->missingTargets(
            $this->bankTargets($folder, $person, CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY),
            $destination,
            $existingLoans->count(),
        ));
    }

    /** @return array<int, array{inquiry_type: string, institution: string, branch: string|null, source: string}> */
    private function bankTargets(ClientFolder $folder, ?CoMaker $person, string $inquiryType): array
    {
        $this->assertPersonBelongsToFolder($folder, $person);

        return $folder->activities()
            ->where('co_maker_id', $person?->id)
            ->whereHas('definition', fn ($query) => $query->where('code', ActivityDefinition::BANK_COOP_CHECK_CODE))
            ->with(['bankTargets' => fn ($query) => $query->where('inquiry_type', $inquiryType)->oldest('id')])
            ->oldest('id')->get()
            ->flatMap(fn ($activity) => $activity->bankTargets->map(fn ($target): array => [
                'inquiry_type' => $target->inquiry_type,
                'institution' => $target->institution_name,
                'branch' => $inquiryType === CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY ? null : $target->branch_location,
                'source' => 'Bank / Coop Check',
            ]))->values()->all();
    }

    /**
     * @param  array<int, array{inquiry_type: string, institution: mixed, branch?: mixed, source?: string}>  $candidates
     * @param  array<int, array{inquiry_type: string, institution: mixed, branch?: mixed}>  $destination
     * @return array<int, array{inquiry_type: string, institution: string, branch: string|null, source: string}>
     */
    private function missingTargets(array $candidates, array $destination, int $destinationCount): array
    {
        $destinationKeys = collect($destination)->map(fn (array $row): string => $this->identity($row))->filter()->flip();
        $seen = [];
        $missing = [];

        foreach ($candidates as $candidate) {
            $row = $this->canonical($candidate);
            $key = $this->identity($row);
            if ($key === '' || isset($seen[$key]) || $destinationKeys->has($key)) {
                continue;
            }
            $seen[$key] = true;
            $missing[] = $row;
        }

        return array_slice($missing, 0, max(0, self::ROW_LIMIT - $destinationCount));
    }

    private function canonical(array $row): array
    {
        $type = $row['inquiry_type'];
        $institution = is_string($row['institution'] ?? null) ? $row['institution'] : '';
        $branch = $type === CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY
            ? null
            : (is_string($row['branch'] ?? null) && $this->normalize($row['branch']) !== '' ? $row['branch'] : null);

        return ['inquiry_type' => $type, 'institution' => $institution, 'branch' => $branch, 'source' => $row['source'] ?? ''];
    }

    private function identity(array $row): string
    {
        $institution = $this->normalize($row['institution'] ?? null);
        if ($institution === '') {
            return '';
        }
        $type = (string) ($row['inquiry_type'] ?? '');
        $branch = $type === CiActivityBankTarget::INQUIRY_TYPE_LOAN_INQUIRY ? '' : $this->normalize($row['branch'] ?? null);

        return $type."\0".$institution."\0".$branch;
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
