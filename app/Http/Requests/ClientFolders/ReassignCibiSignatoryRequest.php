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
                // Same eligibility the dropdown is built from, enforced here so a crafted
                // request cannot name an Administrator, a disabled user, or any other role.
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->whereIn('role', UserRole::cibiSignatoryRoles())
                    ->where('status', UserStatus::Active->value)),
                Rule::notIn([$this->route('cibiReport')->ci_in_charge_id]),
            ],
            // Short reasons are the normal case here ("On leave", "Schedule conflict"), so the
            // rule only insists on some real text — Laravel's TrimStrings makes a whitespace-only
            // submission an empty string, which 'required' then rejects.
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_signatory_id.not_in' => 'Choose a different Credit Investigator than the current signatory.',
            'reason.required' => 'Please provide a reason for reassignment.',
        ];
    }
}
