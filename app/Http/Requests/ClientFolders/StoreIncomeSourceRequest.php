<?php

namespace App\Http\Requests\ClientFolders;

use App\Models\IncomeSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncomeSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [IncomeSource::class, $this->route('clientFolder')]);
    }

    public function rules(): array
    {
        return [
            'income_source_template_id' => ['required', 'integer', Rule::exists('income_source_templates', 'id')->where('is_active', true)],
            'source_name' => ['required', 'string', 'max:255'],
            'business_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['source_name', 'business_name'] as $field) {
            $value = $this->input($field);
            $this->merge([$field => is_string($value) && trim($value) !== '' ? preg_replace('/\s+/u', ' ', trim($value)) : null]);
        }
    }
}
