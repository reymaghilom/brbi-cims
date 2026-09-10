<?php

namespace App\Http\Requests\ClientFolders;

use App\Rules\CiContributorRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIncomeSourceContributorsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('incomeSource'));
    }

    public function rules(): array
    {
        return [
            'contributor_ids' => ['sometimes', 'array', 'max:10'],
            'contributor_ids.*' => [
                'integer',
                'distinct',
                CiContributorRule::exists($this->route('incomeSource')->contributors()->pluck('users.id')),
            ],
        ];
    }
}
