<?php

namespace App\Http\Requests\ClientFolders;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PurgeClientFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('forceDelete', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'string', Rule::in([$this->route('clientFolder')->folder_number])],
        ];
    }
}
