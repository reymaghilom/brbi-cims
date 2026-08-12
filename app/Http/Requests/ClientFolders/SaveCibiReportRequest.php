<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\PartyType;
use App\Models\CibiBankAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveCibiReportRequest extends FormRequest
{
    private const PURPOSE_CODES = ['working_capital', 'business_expansion', 'buyout_debt_consolidation', 'chattel_property_acquisition', 'building_construction_home_renovation', 'real_estate_property_acquisition', 'personal', 'others'];

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function messages(): array
    {
        return [
            'required' => 'This field is required.',
        ];
    }

    public function rules(): array
    {
        $rules = [
            'intent' => ['required', Rule::in(['complete'])],
            'start_date' => ['required', 'date', 'before_or_equal:today'],
            'submitted_date' => ['required', 'date', 'after_or_equal:start_date', 'before_or_equal:today'],
            'party_type' => ['required', Rule::enum(PartyType::class)],
            'branch_name' => ['required', 'string', 'max:255'],
            'account_officer_name' => ['required', 'string', 'max:255'],
            'amount_applied' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'ci_risk_level' => ['required', Rule::in(['very_low', 'low', 'mid', 'high', 'very_high'])],
            'personal_snapshot' => ['required', 'array:name,age,spouse_name,spouse_age,present_address,length_of_stay_months,residence_status,residence_status_from,monthly_rent,living_with_parents,other_residences,home_condition,number_of_storeys,material_cost_level,living_condition,previous_address,previous_address_length_of_stay_months,parents_address,dependents_count,civil_status,separated_year,reputation,barangay_findings,court_background_status,court_background,lifestyle,vehicles_owned,contact_details,other_remarks'],
            'personal_snapshot.name' => ['required', 'string', 'max:255'],
            'personal_snapshot.age' => ['required', 'integer', 'min:0', 'max:150'],
            'personal_snapshot.spouse_name' => ['required', 'string', 'max:255'],
            'personal_snapshot.spouse_age' => ['required', 'integer', 'min:0', 'max:150'],
            'personal_snapshot.present_address' => ['required', 'string', 'max:10000'],
            'personal_snapshot.length_of_stay_months' => ['nullable', 'string', 'max:100'],
            'personal_snapshot.residence_status' => ['required', Rule::in(['Owned', 'Mortgaged', 'Rented', 'Living with Parents'])],
            'personal_snapshot.residence_status_from' => ['nullable', 'string', 'max:255'],
            'personal_snapshot.monthly_rent' => ['nullable', 'string', 'max:255'],
            'personal_snapshot.living_with_parents' => ['nullable', 'boolean'],
            'personal_snapshot.other_residences' => ['nullable', 'string', 'max:10000'],
            'personal_snapshot.home_condition' => ['required', 'string', 'max:60'],
            'personal_snapshot.number_of_storeys' => ['required', 'integer', 'min:0', 'max:100'],
            'personal_snapshot.material_cost_level' => ['required', 'string', 'max:60'],
            'personal_snapshot.living_condition' => ['required', 'string', 'max:60'],
            'personal_snapshot.previous_address' => ['nullable', 'string', 'max:10000'],
            'personal_snapshot.previous_address_length_of_stay_months' => ['nullable', 'string', 'max:100'],
            'personal_snapshot.parents_address' => ['required', 'string', 'max:10000'],
            'personal_snapshot.dependents_count' => ['nullable', 'integer', 'min:0', 'max:100'],
            'personal_snapshot.civil_status' => ['required', 'string', 'max:40'],
            'personal_snapshot.separated_year' => ['nullable', 'string', 'max:100'],
            'personal_snapshot.reputation' => ['required', 'string', 'max:100'],
            'personal_snapshot.barangay_findings' => ['required', 'string', 'max:255'],
            'personal_snapshot.court_background_status' => ['nullable', 'string', 'max:255'],
            'personal_snapshot.court_background' => ['nullable', 'string', 'max:10000'],
            'personal_snapshot.lifestyle' => ['required', 'string', 'max:100'],
            'personal_snapshot.vehicles_owned' => ['nullable', 'string', 'max:10000'],
            'personal_snapshot.contact_details' => ['nullable', 'string', 'max:1000'],
            'personal_snapshot.other_remarks' => ['nullable', 'string', 'max:10000'],
            'summary_totals' => ['nullable', 'array:institutions_checked,institutions_declared,loan_records_found'],
            'summary_totals.*' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'purpose_codes' => ['nullable', 'array', 'max:8'],
            'purpose_codes.*' => [Rule::in(self::PURPOSE_CODES)],
            'purpose_other' => ['nullable', 'string', 'max:255'],
            'purpose_remarks' => ['nullable', 'string', 'max:20000'],
            'negative_credit_findings' => ['nullable', 'string', 'max:30000'],
            'other_remarks' => ['nullable', 'string', 'max:30000'],
            'prepared_by_name' => ['nullable', 'string', 'max:255'],
            'noted_by_name' => ['nullable', 'string', 'max:255'],
            'bank_accounts' => ['sometimes', 'array', 'max:50'],
            'loan_records' => ['sometimes', 'array', 'max:50'],
            'credit_checks' => ['sometimes', 'array', 'max:50'],
            'income_summaries' => ['sometimes', 'array', 'max:50'],
            'legal_findings' => ['sometimes', 'array', 'max:50'],
        ];

        foreach (['bank_accounts', 'loan_records', 'credit_checks', 'income_summaries', 'legal_findings'] as $section) {
            $rules["$section.*.id"] = ['nullable', 'integer', 'distinct'];
            $rules["$section.*._delete"] = ['nullable', 'boolean'];
        }

        return $rules + [
            'bank_accounts.*' => ['array:id,_delete,institution,branch,year_opened,adb_level,adb_level_choice,adb_level_figures,capital_share_amount,capital_share_text,relevant_remarks'],
            'bank_accounts.*.institution' => ['nullable', 'string', 'max:255'],
            'bank_accounts.*.branch' => ['nullable', 'string', 'max:255'],
            'bank_accounts.*.year_opened' => ['nullable', 'integer', 'min:1900', 'max:'.now()->year],
            'bank_accounts.*.adb_level' => ['nullable', 'string', 'max:80'],
            'bank_accounts.*.adb_level_choice' => ['nullable', Rule::in(['low', 'mid', 'high'])],
            'bank_accounts.*.adb_level_figures' => ['nullable', 'string', 'max:80'],
            'bank_accounts.*.capital_share_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'bank_accounts.*.capital_share_text' => ['nullable', 'string', 'max:255'],
            'bank_accounts.*.relevant_remarks' => ['nullable', 'string', 'max:10000'],
            'loan_records.*' => ['array:id,_delete,institution,original_amount,remaining_balance,amortization_amount,granted_date,maturity_date,cycle_number,cycle_label,security_type,payment_performance,combined_findings,remarks'],
            'loan_records.*.institution' => ['nullable', 'string', 'max:255'],
            'loan_records.*.original_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'loan_records.*.remaining_balance' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'loan_records.*.amortization_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'loan_records.*.granted_date' => ['nullable', 'date', 'before_or_equal:today'],
            'loan_records.*.maturity_date' => ['nullable', 'date'],
            'loan_records.*.cycle_number' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'loan_records.*.cycle_label' => ['nullable', 'string', 'max:100'],
            'loan_records.*.security_type' => ['nullable', 'string', 'max:255'],
            'loan_records.*.payment_performance' => ['nullable', 'string', 'max:10000'],
            'loan_records.*.combined_findings' => ['nullable', 'string', 'max:20000'],
            'loan_records.*.remarks' => ['nullable', 'string', 'max:10000'],
            'credit_checks.*' => ['array:id,_delete,institution,branch,is_declared,check_status,checked_date,key_information,remarks'],
            'credit_checks.*.institution' => ['nullable', 'string', 'max:255'], 'credit_checks.*.branch' => ['nullable', 'string', 'max:255'], 'credit_checks.*.is_declared' => ['nullable', 'boolean'], 'credit_checks.*.check_status' => ['nullable', 'string', 'max:50'], 'credit_checks.*.checked_date' => ['nullable', 'date', 'before_or_equal:today'], 'credit_checks.*.key_information' => ['nullable', 'string', 'max:10000'], 'credit_checks.*.remarks' => ['nullable', 'string', 'max:10000'],
            'income_summaries.*' => ['array:id,_delete,income_source_id,source_name,source_type,stability_result,validation_status,key_information,monthly_amount'],
            'income_summaries.*.income_source_id' => ['nullable', 'integer'], 'income_summaries.*.source_name' => ['nullable', 'string', 'max:255'], 'income_summaries.*.source_type' => ['nullable', 'string', 'max:255'], 'income_summaries.*.stability_result' => ['nullable', 'string', 'max:255'], 'income_summaries.*.validation_status' => ['nullable', 'string', 'max:80'], 'income_summaries.*.key_information' => ['nullable', 'string', 'max:10000'], 'income_summaries.*.monthly_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'],
            'legal_findings.*' => ['array:id,_delete,source_level,result,details,checked_at'], 'legal_findings.*.source_level' => ['nullable', 'string', 'max:40'], 'legal_findings.*.result' => ['nullable', 'string', 'max:255'], 'legal_findings.*.details' => ['nullable', 'string', 'max:10000'], 'legal_findings.*.checked_at' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $report = $this->route('clientFolder')->cibiReport;
            $tables = ['bank_accounts' => 'bankAccounts', 'loan_records' => 'loanRecords', 'credit_checks' => 'creditChecks', 'income_summaries' => 'incomeSourceSummaries', 'legal_findings' => 'legalFindings'];
            foreach ($tables as $input => $relation) {
                $allowed = $report?->{$relation}()->pluck('id')->map(fn ($id) => (int) $id)->all() ?? [];
                foreach ((array) $this->input($input, []) as $index => $row) {
                    $id = filled($row['id'] ?? null) ? (int) $row['id'] : null;
                    if ($id !== null && ! in_array($id, $allowed, true)) {
                        if ($input === 'bank_accounts' && ! CibiBankAccount::query()->whereKey($id)->exists()) {
                            continue;
                        }
                        $validator->errors()->add("$input.$index.id", 'The selected child record does not belong to this CI / BI report.');
                    }
                }
            }
            foreach ((array) $this->input('income_summaries', []) as $index => $row) {
                $sourceId = filled($row['income_source_id'] ?? null) ? (int) $row['income_source_id'] : null;
                if ($sourceId !== null && ! $this->route('clientFolder')->incomeSources()->whereKey($sourceId)->exists()) {
                    $validator->errors()->add("income_summaries.$index.income_source_id", 'The selected income source does not belong to this client folder.');
                }
            }
            foreach ((array) $this->input('loan_records', []) as $index => $row) {
                if (filled($row['granted_date'] ?? null) && filled($row['maturity_date'] ?? null) && $row['maturity_date'] < $row['granted_date']) {
                    $validator->errors()->add("loan_records.$index.maturity_date", 'The maturity date must be on or after the granted date.');
                }
            }
            foreach (['bank_accounts' => 'institution', 'loan_records' => 'institution', 'income_summaries' => 'source_name'] as $section => $field) {
                foreach ((array) $this->input($section, []) as $index => $row) {
                    if (! filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOL) && $this->rowHasData($row) && ! filled($row[$field] ?? null)) {
                        $validator->errors()->add("$section.$index.$field", 'This field is required for a populated row.');
                    }
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['branch_name', 'account_officer_name', 'noted_by_name'] as $field) {
            $normalized[$field] = $this->normalize($this->input($field));
        }
        $folder = $this->route('clientFolder');
        $normalized['prepared_by_name'] = $folder->cibiReport?->investigator?->full_name ?? $folder->assignedInvestigator->full_name;
        foreach (['purpose_remarks', 'negative_credit_findings', 'other_remarks'] as $field) {
            $normalized[$field] = $this->trimmed($this->input($field));
        }
        $normalized['purpose_other'] = $this->has('purpose_other') ? $this->normalize($this->input('purpose_other')) : $this->route('clientFolder')->cibiReport?->purpose_other;
        $normalized['amount_applied'] = $this->number($this->input('amount_applied'));

        $personal = (array) $this->input('personal_snapshot', []);
        $personal['name'] = $this->route('clientFolder')->display_name;
        foreach ($personal as $key => $value) {
            $personal[$key] = $key === 'living_with_parents' ? filter_var($value, FILTER_VALIDATE_BOOL) : (is_string($value) ? $this->normalize($value) : $value);
        }
        foreach (['spouse_name', 'present_address', 'length_of_stay_months', 'other_residences', 'previous_address', 'previous_address_length_of_stay_months', 'parents_address', 'separated_year', 'vehicles_owned', 'contact_details', 'other_remarks'] as $field) {
            $personal[$field] = $this->normalize($personal[$field] ?? null);
        }
        $personal['monthly_rent'] = ($personal['residence_status'] ?? null) === 'Rented' ? $this->normalize($personal['monthly_rent'] ?? null) : null;
        $personal['residence_status_from'] = in_array($personal['residence_status'] ?? null, ['Mortgaged', 'Rented'], true) ? $this->normalize($personal['residence_status_from'] ?? null) : null;
        $personal['court_background'] = $this->normalize($personal['court_background'] ?? $this->route('clientFolder')->cibiReport?->personal_snapshot['court_background'] ?? null);
        $normalized['personal_snapshot'] = $personal;

        if ($this->has('summary_totals')) {
            $normalized['summary_totals'] = collect((array) $this->input('summary_totals'))->map(fn ($value) => (int) str_replace(',', '', (string) $value))->all();
        }
        foreach (['bank_accounts', 'loan_records', 'credit_checks', 'income_summaries', 'legal_findings'] as $section) {
            if (! $this->has($section)) {
                continue;
            }
            $normalized[$section] = collect((array) $this->input($section))->map(function ($row) use ($section): array {
                $row = collect((array) $row)->map(fn ($value, $key) => in_array($key, ['_delete', 'is_declared'], true) ? filter_var($value, FILTER_VALIDATE_BOOL) : (is_string($value) ? $this->normalize($value) : $value))->all();
                foreach (['original_amount', 'remaining_balance', 'amortization_amount', 'monthly_amount', 'capital_share_amount'] as $field) {
                    if (array_key_exists($field, $row)) {
                        $row[$field] = $this->number($row[$field]);
                    }
                }
                if ($section === 'bank_accounts') {
                    $choice = $row['adb_level_choice'] ?? null;
                    $figures = $this->normalize($row['adb_level_figures'] ?? null);
                    $row['adb_level'] = $choice
                        ? ucfirst($choice).($figures ? ' / Figures: '.$figures : '')
                        : $figures;
                    $row['capital_share_text'] = $this->normalize($row['capital_share_text'] ?? null);
                }
                if ($section === 'loan_records') {
                    $row['cycle_label'] = $this->normalize($row['cycle_label'] ?? null);
                    if (array_key_exists('combined_findings', $row)) {
                        $row['payment_performance'] = $this->normalize($row['combined_findings']);
                        $row['remarks'] = null;
                    }
                }

                return $row;
            })->values()->all();
        }
        $this->merge($normalized);
    }

    private function rowHasData(array $row): bool
    {
        return collect($row)->except(['id', '_delete', 'is_declared'])->contains(fn ($value) => filled($value));
    }

    private function normalize(mixed $value): ?string
    {
        $value = $this->trimmed($value);

        return $value === null ? null : (string) preg_replace('/\s+/u', ' ', $value);
    }

    private function trimmed(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function number(mixed $value): mixed
    {
        return is_string($value) ? str_replace(',', '', trim($value)) : $value;
    }
}
