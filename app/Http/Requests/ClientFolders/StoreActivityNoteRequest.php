<?php

namespace App\Http\Requests\ClientFolders;

use Illuminate\Foundation\Http\FormRequest;

class StoreActivityNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $folder = $this->route('clientFolder');
        $activity = $this->route('ciActivity');

        return $this->user()->can('update', $folder)
            && $activity !== null
            && $activity->client_folder_id === $folder->id
            && $this->user()->can('update', $activity);
    }

    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:10000'],
            'follow_up_needed' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $note = $this->input('note');
        $this->merge([
            'note' => is_string($note) && trim($note) !== '' ? trim($note) : null,
            'follow_up_needed' => filter_var($this->input('follow_up_needed', false), FILTER_VALIDATE_BOOL),
        ]);
    }
}
