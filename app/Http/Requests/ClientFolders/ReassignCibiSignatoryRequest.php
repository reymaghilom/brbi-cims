<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReassignCibiSignatoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('reassignSignatory', $this->route('cibiReport'));
    }

    public function rules(): array
    {
        return [
            'new_signatory_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', UserRole::CreditInvestigator->value)
                    ->where('status', UserStatus::Active->value)),
                Rule::notIn([$this->route('cibiReport')->ci_in_charge_id]),
            ],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_signatory_id.not_in' => 'Choose a different Credit Investigator than the current signatory.',
        ];
    }
}
