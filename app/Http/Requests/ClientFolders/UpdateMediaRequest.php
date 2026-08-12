<?php

namespace App\Http\Requests\ClientFolders;

use App\Enums\MediaCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $media = $this->route('mediaReference');

        return $media !== null
            && $media->client_folder_id === $this->route('clientFolder')->id
            && $this->user()->can('update', $media);
    }

    public function rules(): array
    {
        return [
            'media_form' => ['nullable', 'string'],
            'category' => ['required', Rule::enum(MediaCategory::class)],
            'label' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'captured_at' => ['nullable', 'date', 'before_or_equal:today'],
            'income_source_id' => ['nullable', 'integer'],
            'ci_activity_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $folder = $this->route('clientFolder');
            if (filled($this->input('income_source_id')) && ! $folder->incomeSources()->whereKey($this->integer('income_source_id'))->exists()) {
                $validator->errors()->add('income_source_id', 'The selected income source does not belong to this client folder.');
            }
            if (filled($this->input('ci_activity_id')) && ! $folder->activities()->whereKey($this->integer('ci_activity_id'))->exists()) {
                $validator->errors()->add('ci_activity_id', 'The selected CI activity does not belong to this client folder.');
            }
            if ($this->input('category') !== MediaCategory::Business->value && filled($this->input('income_source_id'))) {
                $validator->errors()->add('income_source_id', 'Only Business media may be linked to an income source.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'label' => $this->normalized('label'),
            'remarks' => $this->normalized('remarks'),
            'income_source_id' => filled($this->input('income_source_id')) ? $this->input('income_source_id') : null,
            'ci_activity_id' => filled($this->input('ci_activity_id')) ? $this->input('ci_activity_id') : null,
        ]);
    }

    private function normalized(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }
}
