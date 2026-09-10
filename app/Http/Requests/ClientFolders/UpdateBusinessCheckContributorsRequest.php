<?php

namespace App\Http\Requests\ClientFolders;

use App\Rules\CiContributorRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessCheckContributorsRequest extends FormRequest
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
                CiContributorRule::exists($this->route('businessCheck')->contributors()->pluck('users.id')),
            ],
        ];
    }
}
