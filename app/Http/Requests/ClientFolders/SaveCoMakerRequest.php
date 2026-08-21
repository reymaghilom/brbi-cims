<?php

namespace App\Http\Requests\ClientFolders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCoMakerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        return [
            'co_maker_id' => [
                'nullable',
                'integer',
                Rule::exists('co_makers', 'id')->where('client_folder_id', $this->route('clientFolder')->id),
            ],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'suffix' => ['nullable', 'string', 'max:30'],
        ];
    }
}
