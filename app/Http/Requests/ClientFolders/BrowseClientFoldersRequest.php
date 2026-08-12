<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\ClientFolderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrowseClientFoldersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::enum(ClientFolderStatus::class)],
            'sort' => ['nullable', Rule::in(['updated', 'created', 'client_name'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'search' => filled($this->input('search')) ? trim((string) $this->input('search')) : null,
            'status' => filled($this->input('status')) ? $this->input('status') : null,
            'sort' => filled($this->input('sort')) ? $this->input('sort') : 'updated',
        ]);
    }
}
