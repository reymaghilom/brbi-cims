<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\AddressType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateClientInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'suffix' => ['nullable', 'string', 'max:30'],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'civil_status' => ['nullable', 'string', 'max:40'],
            'contact_number' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'spouse_name' => ['nullable', 'string', 'max:255'],
            'length_of_stay_months' => ['nullable', 'integer', 'min:0', 'max:1200'],
            'dependents_count' => ['nullable', 'integer', 'min:0', 'max:100'],
            'home_ownership' => ['nullable', 'string', 'max:60'],
            'home_condition' => ['nullable', 'string', 'max:60'],
            'material_cost_level' => ['nullable', 'string', 'max:60'],
            'living_condition' => ['nullable', 'string', 'max:60'],
            'reputation' => ['nullable', 'string', 'max:100'],
            'lifestyle' => ['nullable', 'string', 'max:100'],
            'vehicles_owned' => ['nullable', 'string', 'max:10000'],
            'other_residences' => ['nullable', 'string', 'max:10000'],
            'barangay_findings' => ['nullable', 'string', 'max:10000'],
            'court_background_summary' => ['nullable', 'string', 'max:10000'],
            'other_remarks' => ['nullable', 'string', 'max:10000'],
            'addresses' => ['array:'.collect(AddressType::cases())->pluck('value')->implode(',')],
        ];

        foreach (AddressType::cases() as $type) {
            $prefix = "addresses.{$type->value}";
            $rules += [
                $prefix => ['sometimes', 'array:enabled,address_line_1,address_line_2,barangay,city_municipality,province,postal_code,country,google_maps_link,is_primary,length_of_stay_months'],
                "$prefix.enabled" => ['nullable', 'boolean'],
                "$prefix.address_line_1" => ["required_if:$prefix.enabled,1", 'nullable', 'string', 'max:255'],
                "$prefix.address_line_2" => ['nullable', 'string', 'max:255'],
                "$prefix.barangay" => ['nullable', 'string', 'max:255'],
                "$prefix.city_municipality" => ['nullable', 'string', 'max:255'],
                "$prefix.province" => ['nullable', 'string', 'max:255'],
                "$prefix.postal_code" => ['nullable', 'string', 'max:20'],
                "$prefix.country" => ['nullable', 'string', 'max:100'],
                "$prefix.google_maps_link" => ['nullable', 'url:http,https', 'max:2048'],
                "$prefix.is_primary" => ['nullable', 'boolean'],
                "$prefix.length_of_stay_months" => ['nullable', 'integer', 'min:0', 'max:1200'],
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $primaryCount = collect($this->input('addresses', []))
                ->filter(fn (mixed $address): bool => is_array($address) && filter_var($address['enabled'] ?? false, FILTER_VALIDATE_BOOL))
                ->filter(fn (array $address): bool => filter_var($address['is_primary'] ?? false, FILTER_VALIDATE_BOOL))
                ->count();

            if ($primaryCount > 1) {
                $validator->errors()->add('addresses', 'Only one enabled address may be marked as primary.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $names = ['first_name', 'middle_name', 'last_name', 'suffix'];
        $shortFields = [
            'civil_status', 'contact_number', 'email', 'spouse_name', 'home_ownership',
            'home_condition', 'material_cost_level', 'living_condition', 'reputation', 'lifestyle',
        ];
        $longFields = ['vehicles_owned', 'other_residences', 'barangay_findings', 'court_background_summary', 'other_remarks'];

        $normalized = [];
        foreach ($names as $field) {
            $normalized[$field] = $this->normalize($this->input($field), true);
        }
        foreach ($shortFields as $field) {
            $normalized[$field] = $this->normalize($this->input($field));
        }
        foreach ($longFields as $field) {
            $normalized[$field] = $this->trimmed($this->input($field));
        }

        $addresses = [];
        foreach (AddressType::cases() as $type) {
            $input = (array) $this->input("addresses.{$type->value}", []);
            $addresses[$type->value] = [
                'enabled' => filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOL),
                'address_line_1' => $this->normalize($input['address_line_1'] ?? null),
                'address_line_2' => $this->normalize($input['address_line_2'] ?? null),
                'barangay' => $this->normalize($input['barangay'] ?? null),
                'city_municipality' => $this->normalize($input['city_municipality'] ?? null),
                'province' => $this->normalize($input['province'] ?? null),
                'postal_code' => $this->normalize($input['postal_code'] ?? null),
                'country' => $this->normalize($input['country'] ?? null) ?? 'Philippines',
                'google_maps_link' => $this->trimmed($input['google_maps_link'] ?? null),
                'is_primary' => filter_var($input['is_primary'] ?? false, FILTER_VALIDATE_BOOL),
                'length_of_stay_months' => $input['length_of_stay_months'] ?? null,
            ];
        }

        $this->merge($normalized + ['addresses' => $addresses]);
    }

    private function normalize(mixed $value, bool $uppercase = false): ?string
    {
        $value = $this->trimmed($value);
        if ($value === null) {
            return null;
        }

        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return $uppercase ? mb_strtoupper($value) : $value;
    }

    private function trimmed(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
