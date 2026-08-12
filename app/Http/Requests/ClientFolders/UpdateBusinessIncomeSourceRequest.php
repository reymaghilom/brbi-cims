<?php

namespace App\Http\Requests\ClientFolders;

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
        $rules = [
            'intent' => ['required', Rule::in(['stay', 'return', 'complete'])],
            'source_name' => ['required', 'string', 'max:255'], 'business_name' => ['required', 'string', 'max:255'],
            'contribution_rank' => ['nullable', 'integer', 'min:1', 'max:65535'], 'estimated_monthly_contribution' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'], 'is_primary' => ['nullable', 'boolean'],
            'branch_name' => ['nullable', 'string', 'max:255'], 'amount_applied' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99'], 'account_officer_name' => ['nullable', 'string', 'max:255'],
            'report_category' => ['required', 'string', 'max:80'], 'main_business_address' => [Rule::requiredIf($complete), 'nullable', 'string', 'max:10000'],
            'previous_business_address' => ['nullable', 'string', 'max:10000'], 'reason_for_transfer' => ['nullable', 'string', 'max:10000'],
            'registered_owner' => [Rule::requiredIf($complete), 'nullable', 'string', 'max:255'], 'relationship_to_borrower' => ['nullable', 'string', 'max:255'],
            'year_established' => ['nullable', 'integer', 'min:1800', 'max:'.now()->year], 'length_of_stay_months' => ['nullable', 'integer', 'min:0', 'max:2400'],
            'monthly_rent' => ['nullable', 'numeric', 'min:0'], 'ownership_type' => ['nullable', 'string', 'max:80'], 'business_type' => ['nullable', 'string', 'max:100'],
            'scale' => ['nullable', 'string', 'max:80'], 'informant' => ['nullable', 'string', 'max:255'], 'report_remarks' => ['nullable', 'string', 'max:30000'],
        ];
        foreach (self::SECTIONS as $section) {
            $rules[$section] = ['array', 'max:50'];
            $rules["$section.*.id"] = ['nullable', 'integer'];
            $rules["$section.*._delete"] = ['nullable', 'boolean'];
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
            $source = $this->route('incomeSource');
            $report = $source->businessReport;
            $tags = $source->template->compatibility_tags ?? [];
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
        foreach (['source_name', 'business_name', 'branch_name', 'account_officer_name', 'report_category', 'registered_owner', 'relationship_to_borrower', 'ownership_type', 'business_type', 'scale', 'informant'] as $field) {
            $data[$field] = $this->short($this->input($field));
        }
        foreach (['main_business_address', 'previous_business_address', 'reason_for_transfer', 'report_remarks'] as $field) {
            $data[$field] = $this->text($this->input($field));
        }
        foreach (self::SECTIONS as $section) {
            $data[$section] = collect((array) $this->input($section, []))->map(fn ($row) => collect((array) $row)->map(fn ($value, $key) => in_array($key, ['_delete', 'is_declared', 'is_inspected', 'has_contract', 'is_air_conditioned', 'is_top_seller', 'is_confirmed'], true) ? filter_var($value, FILTER_VALIDATE_BOOL) : (is_string($value) ? $this->text($value) : $value))->all())->values()->all();
        }
        $this->merge($data);
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
