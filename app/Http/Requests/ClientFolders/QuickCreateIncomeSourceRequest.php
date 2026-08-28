<?php

namespace App\Http\Requests\ClientFolders;

use App\Models\IncomeSource;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the "+ Add Business" quick-create used from the Business Check form when the
 * active Applicant or exact Co-Maker doesn't have a saved Business / Income Source yet.
 * Deliberately minimal — only what
 * CreateIncomeSource actually needs — unlike StoreIncomeSourceRequest, which additionally
 * requires full Business Report profile fields (main_business_address, year_established, etc.)
 * that a field CI doing the Business Check first may not have on hand yet.
 */
class QuickCreateIncomeSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [IncomeSource::class, $this->route('clientFolder')]);
    }

    public function rules(): array
    {
        return [
            'co_maker_id' => ActivePersonResolver::rule($this->route('clientFolder')),
            'business_name' => ['required', 'string', 'max:255'],
            'income_source_template_id' => [
                'required', 'integer',
                Rule::exists('income_source_templates', 'id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->where('is_fallback', false)
                    ->where('form_handler', 'dedicated-business')),
            ],
            // Becomes the new BusinessReport's main_business_address — the one authoritative
            // business address shared with Business Check, same field/cap Business Check's own
            // `location` already validates against (also required there).
            'location' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'location.required' => 'Business location is required.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = is_string($this->input('business_name')) ? trim($this->input('business_name')) : $this->input('business_name');
        $this->merge(['business_name' => $name]);
    }
}
