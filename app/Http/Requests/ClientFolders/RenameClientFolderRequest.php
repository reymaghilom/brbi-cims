<?php

namespace App\Http\Requests\ClientFolders;

use Illuminate\Foundation\Http\FormRequest;

class RenameClientFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        return ['display_name' => ['required', 'string', 'max:255']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'display_name' => filled($this->input('display_name'))
                ? mb_strtoupper((string) preg_replace('/\s+/', ' ', trim((string) $this->input('display_name'))))
                : null,
        ]);
    }
}
