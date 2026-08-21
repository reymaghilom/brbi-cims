<?php

namespace App\Http\Requests\ClientFolders;

use App\Http\Requests\ClientFolders\Concerns\ValidatesCheckPhotoUploads;
use App\Services\ClientFolders\ActivePersonResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveResidenceCheckRequest extends FormRequest
{
    use ValidatesCheckPhotoUploads;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('clientFolder'));
    }

    public function rules(): array
    {
        $folder = $this->route('clientFolder');
        $coMakerId = blank($this->input('co_maker_id')) ? null : (int) $this->input('co_maker_id');

        return [
            'co_maker_id' => ActivePersonResolver::rule($folder),
            'check_id' => [
                'nullable', 'integer',
                Rule::exists('residence_checks', 'id')->where('client_folder_id', $folder->id)->where('co_maker_id', $coMakerId),
            ],
            'ci_date' => ['required', 'date', 'before_or_equal:today'],
            'location' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:10000'],
            'google_maps_link' => ['nullable', 'string', 'max:2048'],
            'removed_photo_ids' => ['nullable', 'array'],
            'removed_photo_ids.*' => ['integer'],
        ] + $this->photoUploadRules('photos');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validatePhotoUploads($validator, 'photos'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['check_id' => filled($this->input('check_id')) ? $this->input('check_id') : null]);
    }
}
