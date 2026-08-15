<?php

namespace App\Http\Requests\ClientFolders;

use App\Models\IncomeSourceTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateBusinessIncomeSourceRequest extends FormRequest
{
    private const SECTIONS = ['properties', 'tenants', 'branches', 'products', 'suppliers', 'observations', 'competitors'];

    public function authorize(): bool
    {
        $folder = $this->route('clientFolder');
        $source = $this->route('incomeSource');

        return $source?->client_folder_id === $folder->id && ! $source->template->is_fallback && $source->template->form_handler === 'dedicated-business' && $this->user()->can('update', $source);
    }

    public function rules(): array
    {
        $complete = $this->input('intent') === 'complete';
        $template = $this->route('incomeSource')?->template
            ?? IncomeSourceTemplate::query()->find($this->integer('income_source_template_id'));
        $schema = $template?->businessReportSchema() ?? [];
        $requiresProfile = $schema === [] || (bool) data_get($schema, 'profile', false);
        $hiddenProfileFields = (array) data_get($schema, 'hidden_profile_fields', []);
        $rules = [
            'intent' => ['required', Rule::in(['stay', 'return', 'complete'])],
            'source_name' => ['required', 'string', 'max:255'], 'business_name' => ['required', 'string', 'max:255'],
            'contribution_rank' => ['nullable', 'integer', 'min:1', 'max:65535'], 'estimated_monthly_contribution' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'], 'is_primary' => ['nullable', 'boolean'],
            'branch_name' => ['nullable', 'string', 'max:255'], 'account_officer_name' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date', 'before_or_equal:today'], 'submitted_date' => ['nullable', 'date', 'before_or_equal:today'],
            'report_category' => ['required', 'string', 'max:80'], 'main_business_address' => [Rule::requiredIf($complete && $requiresProfile), 'nullable', 'string', 'max:10000'],
            'previous_business_address' => ['nullable', 'string', 'max:10000'], 'previous_business_address_length_of_stay' => ['nullable', 'string', 'max:100'], 'reason_for_transfer' => ['nullable', 'string', 'max:10000'],
            'registered_owner' => [Rule::requiredIf($complete && $requiresProfile && ! in_array('registered_owner', $hiddenProfileFields, true)), 'nullable', 'string', 'max:255'], 'relationship_to_borrower' => ['nullable', 'string', 'max:255'],
            'year_established' => ['nullable', 'integer', 'min:1800', 'max:'.now()->year], 'length_of_stay_months' => ['nullable', 'integer', 'min:0', 'max:2400'],
            'monthly_rent' => ['nullable', 'string', 'max:255'], 'ownership_type' => ['nullable', 'string', 'max:80'], 'rented_from' => ['nullable', 'string', 'max:255'], 'business_type' => ['nullable', 'string', 'max:100'],
            'scale' => ['nullable', 'string', 'max:80'], 'informant' => ['nullable', 'string', 'max:255'], 'report_remarks' => ['nullable', 'string', 'max:30000'],
            'template_data' => ['nullable', 'array:fields,tables,questions'],
            'template_data.fields' => ['nullable', 'array'], 'template_data.fields.*' => ['nullable', 'string', 'max:10000'],
            'template_data.tables' => ['nullable', 'array'], 'template_data.tables.*' => ['nullable', 'array', 'max:50'],
            'template_data.tables.*.*' => ['nullable', 'array'], 'template_data.tables.*.*.*' => ['nullable', 'string', 'max:10000'],
            'template_data.questions' => ['nullable', 'array'], 'template_data.questions.*' => ['nullable', 'string', 'max:10000'],
        ];
        foreach (self::SECTIONS as $section) {
            $rules[$section] = ['array', 'max:50'];
            $rules["$section.*.id"] = ['nullable', 'integer'];
            $rules["$section.*._delete"] = ['nullable', 'boolean'];
        }
        if ($this->route('incomeSource')) {
            foreach ((array) data_get($schema, 'required_fields', []) as $fieldKey) {
                $field = collect($schema['fields'] ?? [])->firstWhere('key', $fieldKey);
                $rules["template_data.fields.$fieldKey"] = ($field['type'] ?? 'text') === 'number'
                    ? ['required', 'numeric', 'min:0']
                    : ['required', 'string', 'max:10000'];
            }
            foreach ((array) data_get($schema, 'required_questions', []) as $questionIndex) {
                $rules["template_data.questions.$questionIndex"] = ['required', 'string', 'max:10000'];
            }
        }

        return $rules + [
            'properties.*' => ['array:id,_delete,property_type,is_declared,is_inspected,reason_not_inspected,units_available,units_with_tenants,location,area_square_meters,has_contract,remarks'],
            'properties.*.property_type' => ['nullable', 'string', 'max:80'], 'properties.*.is_declared' => ['nullable', 'boolean'], 'properties.*.is_inspected' => ['nullable', 'boolean'],
            'properties.*.reason_not_inspected' => ['nullable', 'string', 'max:10000'], 'properties.*.units_available' => ['nullable', 'integer', 'min:0'], 'properties.*.units_with_tenants' => ['nullable', 'integer', 'min:0'],
            'properties.*.location' => ['nullable', 'string', 'max:10000'], 'properties.*.area_square_meters' => ['nullable', 'numeric', 'min:0'], 'properties.*.has_contract' => ['nullable', 'boolean'], 'properties.*.remarks' => ['nullable', 'string', 'max:10000'],
            'tenants.*' => ['array:id,_delete,business_property_id,tenant_name,monthly_rent,years_renting,has_contract,contact_details,remarks'],
            'tenants.*.business_property_id' => ['nullable', 'integer'], 'tenants.*.tenant_name' => ['nullable', 'string', 'max:255'], 'tenants.*.monthly_rent' => ['nullable', 'numeric', 'min:0'],
            'tenants.*.years_renting' => ['nullable', 'numeric', 'min:0'], 'tenants.*.has_contract' => ['nullable', 'boolean'], 'tenants.*.contact_details' => ['nullable', 'string', 'max:255'], 'tenants.*.remarks' => ['nullable', 'string', 'max:10000'],
            'branches.*' => ['array:id,_delete,location,is_declared,is_inspected,reason_not_inspected,frontage_meters,total_area_square_meters,is_air_conditioned,operating_days_hours,shifts_count,employees_per_shift,average_sales_per_shift,inventory_level,monthly_rent,years_in_area,nearby_brands'],
            'branches.*.location' => ['nullable', 'string', 'max:10000'], 'branches.*.is_declared' => ['nullable', 'boolean'], 'branches.*.is_inspected' => ['nullable', 'boolean'], 'branches.*.reason_not_inspected' => ['nullable', 'string', 'max:10000'],
            'branches.*.frontage_meters' => ['nullable', 'numeric', 'min:0'], 'branches.*.total_area_square_meters' => ['nullable', 'numeric', 'min:0'], 'branches.*.is_air_conditioned' => ['nullable', 'boolean'], 'branches.*.operating_days_hours' => ['nullable', 'string', 'max:255'],
            'branches.*.shifts_count' => ['nullable', 'integer', 'min:0'], 'branches.*.employees_per_shift' => ['nullable', 'integer', 'min:0'], 'branches.*.average_sales_per_shift' => ['nullable', 'numeric', 'min:0'], 'branches.*.inventory_level' => ['nullable', 'string', 'max:40'],
            'branches.*.monthly_rent' => ['nullable', 'numeric', 'min:0'], 'branches.*.years_in_area' => ['nullable', 'numeric', 'min:0'], 'branches.*.nearby_brands' => ['nullable', 'string', 'max:10000'],
            'products.*' => ['array:id,_delete,product_name,unit_size,selling_price,stock_level,is_top_seller'], 'products.*.product_name' => ['nullable', 'string', 'max:255'], 'products.*.unit_size' => ['nullable', 'string', 'max:255'], 'products.*.selling_price' => ['nullable', 'numeric', 'min:0'], 'products.*.stock_level' => ['nullable', 'string', 'max:40'], 'products.*.is_top_seller' => ['nullable', 'boolean'],
            'suppliers.*' => ['array:id,_delete,supplier_name,office_location,contact_information,is_confirmed,years_transacting,payment_performance,remarks'], 'suppliers.*.supplier_name' => ['nullable', 'string', 'max:255'], 'suppliers.*.office_location' => ['nullable', 'string', 'max:10000'], 'suppliers.*.contact_information' => ['nullable', 'string', 'max:255'], 'suppliers.*.is_confirmed' => ['nullable', 'boolean'], 'suppliers.*.years_transacting' => ['nullable', 'numeric', 'min:0'], 'suppliers.*.payment_performance' => ['nullable', 'string', 'max:255'], 'suppliers.*.remarks' => ['nullable', 'string', 'max:10000'],
            'observations.*' => ['array:id,_delete,observation_code,question_snapshot,answer,remarks'], 'observations.*.observation_code' => ['nullable', 'string', 'max:100'], 'observations.*.question_snapshot' => ['nullable', 'string', 'max:10000'], 'observations.*.answer' => ['nullable', 'string', 'max:10000'], 'observations.*.remarks' => ['nullable', 'string', 'max:10000'],
            'competitors.*' => ['array:id,_delete,name,location,notes'], 'competitors.*.name' => ['nullable', 'string', 'max:255'], 'competitors.*.location' => ['nullable', 'string', 'max:10000'], 'competitors.*.notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $validator->errors()->hasAny(['start_date', 'submitted_date'])
                && filled($this->input('start_date'))
                && filled($this->input('submitted_date'))
                && strtotime((string) $this->input('submitted_date')) < strtotime((string) $this->input('start_date'))) {
                $validator->errors()->add('submitted_date', 'The Business Report submission date must be on or after its start date.');
            }
            $source = $this->route('incomeSource');
            $this->validateTemplateData($validator, $source->template->businessReportSchema());
            $report = $source->businessReport;
            $tags = $source->template->businessReportSchema() !== [] ? [] : ($source->template->compatibility_tags ?? []);
            $relations = ['properties' => 'properties', 'branches' => 'branches', 'products' => 'products', 'suppliers' => 'suppliers', 'observations' => 'observations', 'competitors' => 'competitors'];
            foreach ($relations as $input => $relation) {
                $this->validateRows($validator, $input, $report?->{$relation}()->pluck('id')->all() ?? [], $this->requiredField($input), in_array($input, $tags, true));
            }
            foreach ((array) $this->input('observations', []) as $index => $row) {
                if (! filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOL) && $this->hasData($row) && ! filled($row['question_snapshot'] ?? null)) {
                    $validator->errors()->add("observations.$index.question_snapshot", 'The question or observation is required for a populated row.');
                }
            }
            $allowedProperties = $report?->properties()->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [];
            $allowedTenants = $report?->properties()->with('tenants:id,business_property_id')->get()->pluck('tenants')->flatten()->pluck('id')->map(fn ($id): int => (int) $id)->all() ?? [];
            foreach ((array) $this->input('tenants', []) as $index => $row) {
                if (! in_array('tenants', $tags, true) && $this->hasData($row)) {
                    $validator->errors()->add("tenants.$index", 'Tenant rows are not compatible with this template.');
                }
                if (filled($row['id'] ?? null) && ! in_array((int) $row['id'], $allowedTenants, true)) {
                    $validator->errors()->add("tenants.$index.id", 'The tenant does not belong to this business report.');
                }
                if (! filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOL) && $this->hasData($row)) {
                    if (! filled($row['tenant_name'] ?? null)) {
                        $validator->errors()->add("tenants.$index.tenant_name", 'The tenant name is required for a populated row.');
                    }
                    if (! filled($row['business_property_id'] ?? null) || ! in_array((int) $row['business_property_id'], $allowedProperties, true)) {
                        $validator->errors()->add("tenants.$index.business_property_id", 'Select a saved property from this report.');
                    }
                }
            }
            if ($this->input('intent') === 'complete') {
                foreach (array_intersect(['properties', 'branches', 'products'], $tags) as $section) {
                    if (! collect($this->input($section, []))->contains(fn ($row): bool => ! filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOL) && filled($row[$this->requiredField($section)] ?? null))) {
                        $validator->errors()->add($section, 'At least one compatible row is required before marking this source complete.');
                    }
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $data = ['is_primary' => filter_var($this->input('is_primary', false), FILTER_VALIDATE_BOOL)];
        foreach (['source_name', 'business_name', 'report_category', 'registered_owner', 'relationship_to_borrower', 'ownership_type', 'rented_from', 'previous_business_address_length_of_stay', 'business_type', 'scale', 'informant'] as $field) {
            $data[$field] = $this->short($this->input($field));
        }
        if (! in_array($data['ownership_type'], ['Mortgaged', 'Rented'], true)) {
            $data['rented_from'] = null;
        }
        $source = $this->route('incomeSource');
        $cibiReport = $this->route('clientFolder')?->cibiReport;
        $data['branch_name'] = $source?->branch_name ?: $cibiReport?->branch_name;
        $data['account_officer_name'] = $source?->account_officer_name ?: $cibiReport?->account_officer_name;
        foreach (['main_business_address', 'previous_business_address', 'reason_for_transfer', 'report_remarks'] as $field) {
            $data[$field] = $this->text($this->input($field));
        }
        $report = $source?->businessReport;
        foreach ((array) data_get($source?->template?->businessReportSchema() ?? [], 'hidden_profile_fields', []) as $field) {
            if (in_array($field, ['previous_business_address', 'previous_business_address_length_of_stay', 'reason_for_transfer', 'informant', 'registered_owner', 'relationship_to_borrower'], true)) {
                $data[$field] = $report?->{$field};
            }
        }
        foreach (self::SECTIONS as $section) {
            $data[$section] = collect((array) $this->input($section, []))->map(fn ($row) => collect((array) $row)->map(function ($value, $key) use ($source) {
                if ($source?->template_type === 'retail_grocery_water_refilling' && in_array($key, ['is_air_conditioned', 'is_confirmed'], true)) {
                    return match (strtoupper(trim((string) $value))) {
                        '' => null,
                        'Y', 'YES', '1' => true,
                        'N', 'NO', '0' => false,
                        default => $value,
                    };
                }

                return in_array($key, ['_delete', 'is_declared', 'is_inspected', 'has_contract', 'is_air_conditioned', 'is_top_seller', 'is_confirmed'], true)
                    ? filter_var($value, FILTER_VALIDATE_BOOL)
                    : (is_string($value) ? $this->text($value) : $value);
            })->all())->values()->all();
        }
        if ($source?->template_type === 'retail_grocery_water_refilling') {
            $data['observations'] = collect($data['observations'])
                ->filter(fn (array $row): bool => filled($row['id'] ?? null)
                    || filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOL)
                    || filled($row['answer'] ?? null)
                    || filled($row['remarks'] ?? null))
                ->values()
                ->all();
        }
        $data['template_data'] = $this->cleanTemplateData((array) $this->input('template_data', []));
        foreach ((array) data_get($data, 'template_data.tables', []) as $tableKey => $rows) {
            $data['template_data']['tables'][$tableKey] = collect((array) $rows)
                ->filter(fn ($row): bool => collect((array) $row)->except(['supplier_category'])->contains(fn ($value): bool => filled($value) && $value !== '0'))
                ->values()
                ->all();
        }
        $this->merge($data);
    }

    protected function validateTemplateData(Validator $validator, array $schema): void
    {
        $data = (array) $this->input('template_data', []);
        if ($schema === [] && $data !== []) {
            $validator->errors()->add('template_data', 'Template-specific fields are not available for this Business Template.');

            return;
        }

        $allowedFields = collect($schema['fields'] ?? [])->pluck('key')->all();
        $allowedTables = collect($schema['tables'] ?? [])->pluck('key')->all();
        $questionCount = count($schema['questions'] ?? []);
        foreach (array_keys((array) ($data['fields'] ?? [])) as $key) {
            if (! in_array($key, $allowedFields, true)) {
                $validator->errors()->add("template_data.fields.$key", 'This field does not belong to the selected Business Template.');
            }
        }
        foreach (array_keys((array) ($data['tables'] ?? [])) as $key) {
            if (! in_array($key, $allowedTables, true)) {
                $validator->errors()->add("template_data.tables.$key", 'This table does not belong to the selected Business Template.');

                continue;
            }
            $table = collect($schema['tables'] ?? [])->firstWhere('key', $key);
            $allowedColumns = collect($table['columns'] ?? [])->pluck('key')->all();
            foreach ((array) data_get($data, "tables.$key", []) as $rowIndex => $row) {
                foreach (array_keys((array) $row) as $columnKey) {
                    if (! in_array($columnKey, $allowedColumns, true)) {
                        $validator->errors()->add("template_data.tables.$key.$rowIndex.$columnKey", 'This column does not belong to the selected Business Template.');
                    }
                }
            }
        }
        foreach (array_keys((array) ($data['questions'] ?? [])) as $index) {
            if (! is_numeric($index) || (int) $index >= $questionCount) {
                $validator->errors()->add("template_data.questions.$index", 'This question does not belong to the selected Business Template.');
            }
        }
    }

    private function cleanTemplateData(array $data): array
    {
        return collect($data)->map(function ($value) {
            if (is_array($value)) {
                return $this->cleanTemplateData($value);
            }

            return is_string($value) ? $this->text($value) : $value;
        })->all();
    }

    private function validateRows(Validator $validator, string $section, array $allowedIds, string $requiredField, bool $compatible): void
    {
        $allowedIds = array_map('intval', $allowedIds);
        foreach ((array) $this->input($section, []) as $index => $row) {
            if (filled($row['id'] ?? null) && ! in_array((int) $row['id'], $allowedIds, true)) {
                $validator->errors()->add("$section.$index.id", 'The selected row does not belong to this business report.');
            }
            if (! $compatible && $this->hasData($row)) {
                $validator->errors()->add("$section.$index", 'This section is not compatible with the selected template.');
            }
            if (! filter_var($row['_delete'] ?? false, FILTER_VALIDATE_BOOL) && $this->hasData($row) && ! filled($row[$requiredField] ?? null)) {
                $validator->errors()->add("$section.$index.$requiredField", 'This field is required for a populated row.');
            }
        }
    }

    private function requiredField(string $section): string
    {
        return ['properties' => 'property_type', 'branches' => 'location', 'products' => 'product_name', 'suppliers' => 'supplier_name', 'observations' => 'observation_code', 'competitors' => 'name'][$section];
    }

    private function hasData(array $row): bool
    {
        return collect($row)->except(['id', '_delete', 'is_declared', 'is_inspected', 'has_contract', 'is_air_conditioned', 'is_top_seller', 'is_confirmed'])->contains(fn ($value): bool => filled($value));
    }

    private function short(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value === null ? null : preg_replace('/\s+/u', ' ', $value);
    }

    private function text(mixed $value): ?string
    {
        $value = is_string($value) || is_numeric($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
