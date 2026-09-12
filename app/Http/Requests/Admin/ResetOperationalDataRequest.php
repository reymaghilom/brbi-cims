<?php

namespace App\Http\Requests\Admin;

use App\Actions\Admin\ResetOperationalData;
use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResetOperationalDataRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Administrator only, on top of the route group's role middleware.
        return $this->user()?->can('resetOperationalData', SystemSetting::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Server-side confirmation: the browser's disabled button is a convenience, never the
            // control. A forged request without the exact phrase is rejected here.
            'confirmation' => ['required', 'string', Rule::in([ResetOperationalData::CONFIRMATION_PHRASE])],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Surrounding whitespace is forgiven; the phrase itself stays case-sensitive.
        if (is_string($this->input('confirmation'))) {
            $this->merge(['confirmation' => trim($this->input('confirmation'))]);
        }
    }

    public function messages(): array
    {
        return [
            'confirmation.required' => 'Type RESET DATA to confirm.',
            'confirmation.in' => 'Type RESET DATA exactly to confirm.',
        ];
    }
}
