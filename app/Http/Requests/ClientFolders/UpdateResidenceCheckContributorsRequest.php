<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateResidenceCheckContributorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        return [
            'contributor_ids' => ['sometimes', 'array', 'max:10'],
            'contributor_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(fn ($query) => $query
                    ->where('role', UserRole::CreditInvestigator->value)
                    ->where('status', UserStatus::Active->value)),
            ],
        ];
    }
}
